<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Installer;
use DSGo_Apps\InstallerError;
use DSGo_Apps\PostType;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Lite 1-active-app cap. Pro lifts the cap via the
 * `dsgo_apps_lite_app_cap` filter; tests assert both Lite enforcement
 * and the filter-based lift.
 */
class InstallerCapTest extends WP_UnitTestCase {

    protected int $admin_id;

    public function set_up(): void {
        parent::set_up();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $uploads_base = wp_upload_dir()['basedir'] . '/dsgo-apps/';
        foreach (['cap-one', 'cap-two', 'cap-three', 'cap-update', 'cap-trashed', 'cap-lifted-a', 'cap-lifted-b'] as $id) {
            \DSGo_Apps\Bundle::recursive_delete($uploads_base . $id);
            foreach (glob($uploads_base . $id . '.previous-*') ?: [] as $stash) {
                \DSGo_Apps\Bundle::recursive_delete($stash);
            }
        }
        // Strip every dsgo_app post and any leftover filter listeners between
        // tests so the cap state is deterministic.
        $this->purge_apps();
        remove_all_filters('dsgo_apps_lite_app_cap');
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_lite_app_cap');
        $this->purge_apps();
        parent::tear_down();
    }

    public function test_first_install_succeeds_under_default_cap(): void {
        $result = Installer::install($this->build_minimal_zip('cap-one'), $this->admin_id);
        $this->assertSame('cap-one', $result->app_id);
        $this->assertSame(1, Installer::count_published_apps());
    }

    public function test_second_install_throws_lite_cap_reached(): void {
        Installer::install($this->build_minimal_zip('cap-one'), $this->admin_id);

        try {
            Installer::install($this->build_minimal_zip('cap-two'), $this->admin_id);
            $this->fail('Expected InstallerError lite_cap_reached');
        } catch (InstallerError $e) {
            $this->assertSame('lite_cap_reached', $e->error_code);
            $this->assertStringContainsString('1 active app', $e->bare_message);
            $this->assertStringContainsString('Riff', $e->bare_message);
        }

        // The rejected install must not leave a post or bundle behind.
        $this->assertNull(get_page_by_path('cap-two', OBJECT, PostType::SLUG));
        $this->assertSame(1, Installer::count_published_apps());
    }

    public function test_reinstall_of_same_slug_is_an_update_not_a_new_app(): void {
        Installer::install($this->build_minimal_zip('cap-update'), $this->admin_id);
        // Re-install the same slug. Cap is at 1, but this is an update of
        // the existing post, so it must succeed.
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

        // Installing a different slug now succeeds — the trashed app
        // doesn't count, so we're back under the cap.
        $result = Installer::install($this->build_minimal_zip('cap-two'), $this->admin_id);
        $this->assertSame('cap-two', $result->app_id);
    }

    public function test_filter_returning_null_lifts_the_cap(): void {
        // Mirrors what Pro does when a license is active.
        add_filter('dsgo_apps_lite_app_cap', '__return_null');

        Installer::install($this->build_minimal_zip('cap-lifted-a'), $this->admin_id);
        Installer::install($this->build_minimal_zip('cap-lifted-b'), $this->admin_id);

        $this->assertSame(2, Installer::count_published_apps());
    }

    public function test_filter_returning_higher_cap_allows_more_installs(): void {
        // Operator override: bump the cap to 3 without claiming Pro.
        add_filter('dsgo_apps_lite_app_cap', static fn () => 3);

        Installer::install($this->build_minimal_zip('cap-one'), $this->admin_id);
        Installer::install($this->build_minimal_zip('cap-two'), $this->admin_id);
        Installer::install($this->build_minimal_zip('cap-three'), $this->admin_id);
        $this->assertSame(3, Installer::count_published_apps());

        // Fourth install must still be rejected — the cap moved, not vanished.
        try {
            Installer::install($this->build_minimal_zip('cap-update'), $this->admin_id);
            $this->fail('Expected InstallerError lite_cap_reached at cap=3');
        } catch (InstallerError $e) {
            $this->assertSame('lite_cap_reached', $e->error_code);
        }
    }

    public function test_lite_app_cap_helper_returns_default(): void {
        $this->assertSame(1, Installer::lite_app_cap());
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
