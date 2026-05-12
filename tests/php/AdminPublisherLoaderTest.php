<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AdminPublisherLoader;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

class AdminPublisherLoaderTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Existing tests assert the island is emitted — open the gate so they
        // are not silently suppressed by the ProFeatureGate check.
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        // Ensure cache resets between tests so each one sees its own posts.
        $reflection = new \ReflectionClass(AdminPublisherLoader::class);
        if ($reflection->hasProperty('collected')) {
            $prop = $reflection->getProperty('collected');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $posts = get_posts(['post_type' => PostType::SLUG, 'numberposts' => -1, 'post_status' => 'any']);
        foreach ($posts as $p) {
            wp_delete_post($p->ID, true);
        }
        // Reset cache again so gate-closed tests don't bleed into others.
        $reflection = new \ReflectionClass(AdminPublisherLoader::class);
        if ($reflection->hasProperty('collected')) {
            $prop = $reflection->getProperty('collected');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
        parent::tear_down();
    }

    public function test_emits_island_with_publishing_apps_only(): void {
        $this->install_app('app-a', [['name' => 'app-a/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content']]);
        $this->install_app('app-b', []);  // no publishes — should be excluded
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertStringContainsString('id="dsgo-publisher-config"', $html);
        $this->assertMatchesRegularExpression('/"id":"app-a"/', $html);
        $this->assertStringNotContainsString('"id":"app-b"', $html);
        $this->assertMatchesRegularExpression('/"name":"app-a\\\\\/foo"/', $html);
    }

    public function test_emits_no_island_for_anonymous_user(): void {
        $this->install_app('app-a', [['name' => 'app-a/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content']]);
        wp_set_current_user(0);
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_emits_no_island_when_no_apps_have_publishes(): void {
        $this->install_app('app-x', []);
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_island_includes_rest_root_and_nonce(): void {
        $this->install_app('app-a', [['name' => 'app-a/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content']]);
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/"rest_root":/', $html);
        $this->assertMatchesRegularExpression('/"rest_nonce":"[a-f0-9]+"/', $html);
    }

    public function test_inline_mode_app_appears_with_host_bundle_url(): void {
        $this->install_app('inline-pub', [[
            'name' => 'inline-pub/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content',
        ]], 'inline');
        $user = $this->factory->user->create(['role' => 'editor']);
        wp_set_current_user($user);
        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();
        $this->assertStringContainsString('id="dsgo-publisher-config"', $html);
        $payload = json_decode(
            (string) preg_replace('#.*<script[^>]*id="dsgo-publisher-config"[^>]*>(.*?)</script>.*#s', '$1', $html),
            true,
        );
        $this->assertIsArray($payload);
        $apps = array_values(array_filter($payload['apps'], fn ($a) => $a['id'] === 'inline-pub'));
        $this->assertCount(1, $apps);
        $url_path = (string) wp_parse_url($apps[0]['bundle_url'], PHP_URL_PATH);
        $this->assertStringEndsWith('/__dsgo-host', $url_path);
    }

    public function test_inline_root_mount_app_uses_root_host_url(): void {
        $this->install_app('inline-root', [[
            'name' => 'inline-root/bar', 'label' => 'Bar', 'description' => 'd', 'category' => 'content',
        ]], 'inline', 'root');
        $user = $this->factory->user->create(['role' => 'editor']);
        wp_set_current_user($user);
        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();
        $payload = json_decode(
            (string) preg_replace('#.*<script[^>]*id="dsgo-publisher-config"[^>]*>(.*?)</script>.*#s', '$1', $html),
            true,
        );
        $apps = array_values(array_filter($payload['apps'], fn ($a) => $a['id'] === 'inline-root'));
        $this->assertCount(1, $apps);
        $url_path = (string) wp_parse_url($apps[0]['bundle_url'], PHP_URL_PATH);
        $this->assertSame('/__dsgo-host', $url_path);
    }

    public function test_iframe_mode_app_bundle_url_unchanged(): void {
        $this->install_app('iframe-pub', [[
            'name' => 'iframe-pub/baz', 'label' => 'Baz', 'description' => 'd', 'category' => 'content',
        ]]);
        $user = $this->factory->user->create(['role' => 'editor']);
        wp_set_current_user($user);
        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();
        $payload = json_decode(
            (string) preg_replace('#.*<script[^>]*id="dsgo-publisher-config"[^>]*>(.*?)</script>.*#s', '$1', $html),
            true,
        );
        $apps = array_values(array_filter($payload['apps'], fn ($a) => $a['id'] === 'iframe-pub'));
        $this->assertCount(1, $apps);
        $this->assertStringContainsString('/index.html', (string) $apps[0]['bundle_url']);
        $this->assertStringNotContainsString('__dsgo-host', (string) $apps[0]['bundle_url']);
    }

    public function test_inline_prefixed_app_with_empty_url_prefix_avoids_double_slash(): void {
        update_option('dsgo_apps_url_prefix', '');
        \DSGo_Apps\Settings::refresh_root_app_id();
        try {
            $this->install_app('inline-empty', [[
                'name' => 'inline-empty/x', 'label' => 'X', 'description' => 'd', 'category' => 'content',
            ]], 'inline');
            $user = $this->factory->user->create(['role' => 'editor']);
            wp_set_current_user($user);
            ob_start();
            AdminPublisherLoader::emit_config_island();
            $html = ob_get_clean();
            $payload = json_decode(
                (string) preg_replace('#.*<script[^>]*id="dsgo-publisher-config"[^>]*>(.*?)</script>.*#s', '$1', $html),
                true,
            );
            $apps = array_values(array_filter($payload['apps'], fn ($a) => $a['id'] === 'inline-empty'));
            $this->assertCount(1, $apps);
            $url_path = (string) wp_parse_url($apps[0]['bundle_url'], PHP_URL_PATH);
            $this->assertStringNotContainsString('//', $url_path);
            $this->assertStringEndsWith('/inline-empty/__dsgo-host', $url_path);
        } finally {
            update_option('dsgo_apps_url_prefix', 'apps');
            \DSGo_Apps\Settings::refresh_root_app_id();
        }
    }

    public function test_emit_config_island_suppressed_when_gate_closed(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $this->install_app('pub-gate', [['name' => 'pub-gate/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content']]);
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertSame(
            '',
            $html,
            'emit_config_island must not output when ProFeatureGate("abilities_publish") is closed'
        );
    }

    public function test_emit_config_island_present_when_gate_open(): void {
        // Gate is already open via set_up; assert the island is emitted.
        $this->install_app('pub-gate2', [['name' => 'pub-gate2/bar', 'label' => 'L', 'description' => 'd', 'category' => 'content']]);
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));
        set_current_screen('edit-post');

        ob_start();
        AdminPublisherLoader::emit_config_island();
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'id="dsgo-publisher-config"',
            $html,
            'emit_config_island must output the island when ProFeatureGate("abilities_publish") is open'
        );
    }

    private function install_app(string $id, array $publishes, string $isolation = 'iframe', string $mount_mode = 'prefixed'): void {
        $post_id = $this->factory->post->create([
            'post_type' => PostType::SLUG,
            'post_status' => 'publish',
            'post_name' => $id,
            'post_title' => $id,
        ]);
        $manifest = [
            'manifest_version' => 1, 'id' => $id, 'name' => $id, 'version' => '0.1.0',
            'entry' => 'index.html', 'isolation' => $isolation,
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ];
        if ($isolation === 'inline') {
            $manifest['routes']  = [['path' => '/', 'file' => 'index.html']];
            $manifest['runtime'] = ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]];
        }
        if ($mount_mode === 'root') {
            $manifest['mount'] = ['mode' => 'root'];
        }
        if ($publishes !== []) {
            $manifest['abilities'] = ['publishes' => $publishes];
        }
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
    }
}
