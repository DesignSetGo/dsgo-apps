<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\InlineRenderer;
use DSGo_Apps\IframeLoader;
use DSGo_Apps\Plugin;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

class PluginTest extends WP_UnitTestCase {
    public function test_singleton_returns_same_instance(): void {
        $a = Plugin::get_instance();
        $b = Plugin::get_instance();
        $this->assertSame($a, $b);
    }

    public function test_constants_defined(): void {
        $this->assertTrue(defined('DSGO_APPS_VERSION'));
        $this->assertTrue(defined('DSGO_APPS_PATH'));
        $this->assertTrue(defined('DSGO_APPS_URL'));
    }

    public function test_activation_notice_points_to_starter_and_artifact_paths(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        set_transient('dsgo_apps_activation_notice', '1', 60);

        ob_start();
        Plugin::maybe_render_activation_notice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Install the starter app', $html);
        $this->assertStringContainsString('upload an HTML artifact', $html);
        $this->assertStringNotContainsString('npx designsetgo apps deploy', $html);
    }

    public function test_editor_preview_dispatch_runs_before_root_app_dispatch(): void {
        Plugin::get_instance();

        $block_preview_priority = has_action('template_redirect', [IframeLoader::class, 'maybe_render_block']);
        $inline_root_priority   = has_action('template_redirect', [InlineRenderer::class, 'maybe_dispatch_root']);
        $iframe_root_priority   = has_action('template_redirect', [IframeLoader::class, 'maybe_dispatch_root']);

        $this->assertIsInt($block_preview_priority);
        $this->assertIsInt($inline_root_priority);
        $this->assertIsInt($iframe_root_priority);
        $this->assertLessThan($inline_root_priority, $block_preview_priority);
        $this->assertLessThan($iframe_root_priority, $block_preview_priority);
    }

    public function test_woocommerce_update_product_bumps_cache_for_subscribed_apps(): void {
        if (!function_exists('wc_get_product')) {
            $this->markTestSkipped('WooCommerce not loaded; cache-bump hook for wc:products needs WC.');
        }

        $manifest_arr = [
            'manifest_version' => 1, 'id' => 'shop-app', 'name' => 'Shop',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/shop/:slug', 'file' => 'product.html',
                 'dataset' => ['source' => 'wc:products', 'id_field' => 'slug']],
            ],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ];
        $app_post = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'shop-app',
            'post_title'  => 'Shop',
        ]);
        update_post_meta($app_post, 'dsgo_apps_manifest', $manifest_arr);

        $v_before = InlineRenderer::cache_version('shop-app');

        $product = new \WC_Product_Simple();
        $product->set_name('Cache-Bump Test');
        $product->set_status('publish');
        $product->set_regular_price('5.00');
        $product->save();

        $v_after = InlineRenderer::cache_version('shop-app');
        $this->assertNotSame($v_before, $v_after, 'wc:products app cache version must rotate on product save');

        $product->delete(true);
        wp_delete_post($app_post, true);
        delete_option('dsgo_app_cache_version_shop-app');
    }
}
