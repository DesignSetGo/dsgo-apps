<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AdminPublisherLoader;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

class AdminPublisherLoaderTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Ensure cache resets between tests so each one sees its own posts.
        $reflection = new \ReflectionClass(AdminPublisherLoader::class);
        if ($reflection->hasProperty('collected')) {
            $prop = $reflection->getProperty('collected');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    public function tear_down(): void {
        $posts = get_posts(['post_type' => PostType::SLUG, 'numberposts' => -1, 'post_status' => 'any']);
        foreach ($posts as $p) {
            wp_delete_post($p->ID, true);
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

    private function install_app(string $id, array $publishes): void {
        $post_id = $this->factory->post->create([
            'post_type' => PostType::SLUG,
            'post_status' => 'publish',
            'post_name' => $id,
            'post_title' => $id,
        ]);
        $manifest = [
            'manifest_version' => 1, 'id' => $id, 'name' => $id, 'version' => '0.1.0',
            'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ];
        if ($publishes !== []) {
            $manifest['abilities'] = ['publishes' => $publishes];
        }
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
    }
}
