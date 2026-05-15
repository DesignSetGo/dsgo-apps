<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AdminPage;
use DSGo_Apps\PostType;
use DSGo_Apps\Settings;
use WP_UnitTestCase;

class AdminPageTest extends WP_UnitTestCase {

    private int $admin_id;
    private int $editor_id;

    public function set_up(): void {
        parent::set_up();
        $this->admin_id  = $this->factory->user->create(['role' => 'administrator']);
        $this->editor_id = $this->factory->user->create(['role' => 'editor']);
    }

    public function test_register_menu_adds_top_level_page_for_admins(): void {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];
        wp_set_current_user($this->admin_id);
        AdminPage::register_menu();
        $slugs = array_map(static fn ($entry) => $entry[2] ?? null, $menu);
        $this->assertContains(AdminPage::MENU_SLUG, $slugs, 'top-level menu should be registered');
    }

    public function test_enqueue_assets_skips_when_hook_is_other_screen(): void {
        wp_set_current_user($this->admin_id);
        AdminPage::enqueue_assets('dashboard');
        $this->assertFalse(wp_style_is('dsgo-admin-page', 'enqueued'));
        $this->assertFalse(wp_script_is('dsgo-admin-page', 'enqueued'));
    }

    public function test_enqueue_assets_loads_on_top_level_hook(): void {
        wp_set_current_user($this->admin_id);
        AdminPage::enqueue_assets('toplevel_page_' . AdminPage::MENU_SLUG);
        $this->assertTrue(wp_style_is('dsgo-admin-page', 'enqueued'));
        $this->assertTrue(wp_script_is('dsgo-admin-page', 'enqueued'));
        // Cleanup so cross-test side effects don't bleed.
        wp_dequeue_style('dsgo-admin-page');
        wp_dequeue_script('dsgo-admin-page');
    }

    public function test_success_embed_url_opens_new_page_editor(): void {
        wp_set_current_user($this->admin_id);
        AdminPage::enqueue_assets('toplevel_page_' . AdminPage::MENU_SLUG);

        global $wp_scripts;
        $registered = $wp_scripts->registered['dsgo-admin-page'] ?? null;
        $this->assertNotNull($registered);

        $data = (string) ($registered->extra['data'] ?? '');
        $this->assertStringContainsString('"newPostUrl":"' . admin_url('post-new.php?post_type=page'), $data);
        $this->assertStringNotContainsString('"newPostUrl":"' . admin_url('post-new.php') . '"', $data);

        wp_dequeue_style('dsgo-admin-page');
        wp_dequeue_script('dsgo-admin-page');
    }

    public function test_enqueue_assets_falls_back_to_version_when_css_missing(): void {
        // The filemtime() guard must not throw a warning when the css is absent.
        wp_set_current_user($this->admin_id);
        AdminPage::enqueue_assets('toplevel_page_' . AdminPage::MENU_SLUG);
        global $wp_styles;
        $registered = $wp_styles->registered['dsgo-admin-page'] ?? null;
        $this->assertNotNull($registered);
        $this->assertNotSame('', (string) $registered->ver);
        wp_dequeue_style('dsgo-admin-page');
        wp_dequeue_script('dsgo-admin-page');
    }

    public function test_empty_admin_page_leads_with_artifact_and_starter_paths(): void {
        wp_set_current_user($this->admin_id);
        ob_start();
        AdminPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-dsgo-quick-action="starter"', $html);
        $this->assertStringContainsString('data-dsgo-quick-action="artifact"', $html);
        $this->assertStringContainsString('data-dsgo-tab="html"', $html);
        $this->assertStringContainsString('data-dsgo-tab="upload"', $html);
        $this->assertStringContainsString('id="dsgo-panel-html"', $html);
        $this->assertStringContainsString('id="dsgo-panel-upload"', $html);
        $this->assertLessThan(
            strpos($html, 'data-dsgo-tab="upload"'),
            strpos($html, 'data-dsgo-tab="html"'),
            'artifact upload should be the first install tab',
        );
        $this->assertStringContainsString('aria-selected="true" aria-controls="dsgo-panel-html"', $html);
        $this->assertStringContainsString('class="dsgo-panel" data-dsgo-panel="html"', $html);
        $this->assertStringContainsString('class="dsgo-panel" data-dsgo-panel="upload" hidden', $html);
    }

    public function test_admin_page_renders_post_install_success_actions_template(): void {
        wp_set_current_user($this->admin_id);
        ob_start();
        AdminPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-dsgo-success-template', $html);
        $this->assertStringContainsString('data-dsgo-success-open', $html);
        $this->assertStringContainsString('data-dsgo-success-embed', $html);
        $this->assertStringContainsString('data-dsgo-success-home', $html);
        $this->assertStringContainsString('data-dsgo-success-copy', $html);
        $this->assertStringContainsString('Embed in a page', $html);
        $this->assertStringNotContainsString('Embed in a post', $html);
    }

    public function test_reading_notice_skipped_for_non_admin(): void {
        wp_set_current_user($this->editor_id);
        ob_start();
        AdminPage::maybe_render_reading_notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_reading_notice_skipped_when_no_root_app(): void {
        wp_set_current_user($this->admin_id);
        update_option(Settings::OPTION_ROOT_APP_ID, '');
        // Force the screen helper to return options-reading.
        set_current_screen('options-reading');
        ob_start();
        AdminPage::maybe_render_reading_notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_inactive_pro_features_lists_all_gated_features_in_manifest(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $manifest = [
            'scheduled' => ['jobs' => [['id' => 'sync']]],
            'webhooks'  => ['endpoints' => [['path' => '/inbound']]],
            'abilities' => ['publishes' => [['name' => 'foo']]],
            'routes'    => [['path' => '/posts/:slug', 'dataset' => ['source' => 'wp:posts']]],
        ];
        $inactive = AdminPage::inactive_pro_features_for_manifest($manifest);
        sort($inactive);
        $this->assertSame(['abilities_publish', 'cron', 'dynamic_routes', 'webhooks'], $inactive);
    }

    public function test_inactive_pro_features_returns_empty_when_gate_is_open(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        $manifest = ['scheduled' => ['jobs' => [['id' => 'sync']]]];
        $this->assertSame([], AdminPage::inactive_pro_features_for_manifest($manifest));
        remove_all_filters('dsgo_apps_pro_feature_enabled');
    }

    public function test_inactive_pro_features_returns_empty_when_manifest_declares_no_pro_features(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $this->assertSame([], AdminPage::inactive_pro_features_for_manifest([
            'routes' => [['path' => '/'], ['path' => '/about']], // static routes only
        ]));
    }

    public function test_dynamic_routes_badge_skips_bundle_json_sources(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $manifest = [
            'routes' => [['path' => '/items', 'dataset' => ['source' => 'data/items.json']]],
        ];
        $this->assertSame([], AdminPage::inactive_pro_features_for_manifest($manifest));
    }

    public function test_dynamic_routes_badge_triggers_on_wp_sources(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $manifest = [
            'routes' => [['path' => '/posts/:slug', 'dataset' => ['source' => 'wp:posts']]],
        ];
        $this->assertSame(['dynamic_routes'], AdminPage::inactive_pro_features_for_manifest($manifest));
    }

    public function test_dynamic_routes_badge_triggers_on_wc_sources(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $manifest = [
            'routes' => [['path' => '/p/:slug', 'dataset' => ['source' => 'wc:products']]],
        ];
        $this->assertSame(['dynamic_routes'], AdminPage::inactive_pro_features_for_manifest($manifest));
    }

    public function test_reading_notice_renders_when_root_app_is_set(): void {
        wp_set_current_user($this->admin_id);
        $post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'home-app',
            'post_title'  => 'My Home App',
        ]);
        update_option(Settings::OPTION_ROOT_APP_ID, 'home-app');
        set_current_screen('options-reading');
        ob_start();
        AdminPage::maybe_render_reading_notice();
        $html = (string) ob_get_clean();
        $this->assertNotSame('', $html);
        $this->assertStringContainsString('My Home App', $html);
        $this->assertStringContainsString('admin.php?page=' . AdminPage::MENU_SLUG, $html);
        wp_delete_post($post_id, true);
        delete_option(Settings::OPTION_ROOT_APP_ID);
    }
}
