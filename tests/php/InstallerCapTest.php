<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Installer;
use DSGo_Apps\PostType;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Installer cap-related behavior: unlimited installs succeed under the
 * default (cap-off) configuration, same-slug re-installs are updates,
 * and count_published_apps tracks only published posts.
 *
 * The dsgo_apps_lite_app_cap filter is preserved as a back-compat no-op
 * (Task 1 of the free/Pro split kept the filter callable but flipped the
 * default to null). Tests here exercise the cap-off path; the prior
 * cap-enforcement tests were removed in Task 2 of that plan.
 */
class InstallerCapTest extends WP_UnitTestCase {

    protected int $admin_id;

    public function set_up(): void {
        parent::set_up();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $uploads_base = wp_upload_dir()['basedir'] . '/designsetgo-apps/';
        foreach (['cap-one', 'cap-two', 'cap-three', 'cap-update', 'cap-trashed', 'cap-lifted-a', 'cap-lifted-b'] as $id) {
            \DSGo_Apps\Bundle::recursive_delete($uploads_base . $id);
            foreach (glob($uploads_base . $id . '.previous-*') ?: [] as $stash) {
                \DSGo_Apps\Bundle::recursive_delete($stash);
            }
        }
        $this->purge_apps();
        remove_all_filters('dsgo_apps_lite_app_cap');
    }

    public function tear_down(): void {
        // Strip every dsgo_app post and any leftover filter listeners between tests so the cap state is deterministic.
        remove_all_filters('dsgo_apps_lite_app_cap');
        $this->purge_apps();
        parent::tear_down();
    }

    public function test_first_install_succeeds_under_default_cap(): void {
        $result = Installer::install($this->build_minimal_zip('cap-one'), $this->admin_id);
        $this->assertSame('cap-one', $result->app_id);
        $this->assertSame(1, Installer::count_published_apps());
    }

    public function test_second_install_also_succeeds(): void {
        Installer::install($this->build_minimal_zip('cap-one'), $this->admin_id);
        $result = Installer::install($this->build_minimal_zip('cap-two'), $this->admin_id);
        $this->assertSame('cap-two', $result->app_id);
        $this->assertSame(2, Installer::count_published_apps());
    }

    public function test_reinstall_of_same_slug_is_an_update_not_a_new_app(): void {
        Installer::install($this->build_minimal_zip('cap-update'), $this->admin_id);
        $result = Installer::install($this->build_minimal_zip('cap-update'), $this->admin_id);
        $this->assertSame('cap-update', $result->app_id);
        $this->assertSame(1, Installer::count_published_apps());
    }

    public function test_trashed_app_does_not_count_against_cap(): void {
        Installer::install($this->build_minimal_zip('cap-trashed'), $this->admin_id);
        $post = get_page_by_path('cap-trashed', OBJECT, PostType::SLUG);
        $this->assertNotNull($post);
        wp_trash_post($post->ID);

        $this->assertSame(0, Installer::count_published_apps());

        $result = Installer::install($this->build_minimal_zip('cap-two'), $this->admin_id);
        $this->assertSame('cap-two', $result->app_id);
    }

    public function test_lite_cap_is_disabled_by_default(): void {
        $this->assertNull(Installer::lite_app_cap());
    }

    public function test_lite_app_cap_helper_returns_null_when_filter_disables(): void {
        add_filter('dsgo_apps_lite_app_cap', '__return_false');
        $this->assertNull(Installer::lite_app_cap());
    }

    private function purge_apps(): void {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        foreach ($posts as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    private function build_minimal_zip(string $id): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-cap-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => $id,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return $tmp;
    }
}
