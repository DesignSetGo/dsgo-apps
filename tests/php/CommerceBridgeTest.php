<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CommerceBridge;
use DSGo_Apps\Manifest;
use DSGo_Apps\Permission;
use WP_UnitTestCase;

class CommerceBridgeTest extends WP_UnitTestCase {

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_can_invoke_commerce');
        parent::tear_down();
    }

    private function manifest_with_endpoints(array $endpoints, array $providers = ['woocommerce']): Manifest {
        return Manifest::validate([
            'manifest_version' => 1, 'id' => 'shop', 'name' => 'Shop',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['commerce'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'commerce' => ['providers' => $providers, 'endpoints' => $endpoints],
        ]);
    }

    // --- endpoint_for_action -------------------------------------------------

    public function test_endpoint_for_action_maps_known_actions(): void {
        $this->assertSame('products', CommerceBridge::endpoint_for_action('products.list'));
        $this->assertSame('products', CommerceBridge::endpoint_for_action('products.get'));
        $this->assertSame('cart',     CommerceBridge::endpoint_for_action('cart.add_item'));
        $this->assertSame('cart',     CommerceBridge::endpoint_for_action('cart.get'));
        $this->assertSame('checkout', CommerceBridge::endpoint_for_action('checkout.open_hosted_page'));
    }

    public function test_endpoint_for_action_returns_null_for_unknown(): void {
        $this->assertNull(CommerceBridge::endpoint_for_action('random.thing'));
        $this->assertNull(CommerceBridge::endpoint_for_action('cart'));
    }

    public function test_endpoint_in_manifest_respects_declaration(): void {
        $m = $this->manifest_with_endpoints(['products', 'cart']);
        $this->assertTrue(CommerceBridge::endpoint_in_manifest('products', $m));
        $this->assertTrue(CommerceBridge::endpoint_in_manifest('cart',     $m));
        $this->assertFalse(CommerceBridge::endpoint_in_manifest('checkout', $m));
    }

    // --- invoke gating -------------------------------------------------------

    public function test_invoke_unknown_method_returns_unknown_method(): void {
        $m = $this->manifest_with_endpoints(['products']);
        $r = CommerceBridge::invoke('does.not.exist', [], $m, 0);
        $this->assertFalse($r['ok']);
        $this->assertSame('unknown_method', $r['code']);
    }

    public function test_invoke_endpoint_not_in_manifest_returns_permission_denied(): void {
        $m = $this->manifest_with_endpoints(['products']);
        $r = CommerceBridge::invoke('cart.get', [], $m, 0);
        $this->assertFalse($r['ok']);
        $this->assertSame('permission_denied', $r['code']);
        $this->assertSame('not_in_endpoints', $r['reason'] ?? null);
    }

    public function test_invoke_no_provider_active_returns_not_implemented(): void {
        // WooCommerce isn't loaded in the test env, so any commerce call
        // should bail with not_implemented before touching the Store API.
        $m = $this->manifest_with_endpoints(['products', 'cart']);
        $r = CommerceBridge::invoke('products.list', [], $m, 0);
        if (CommerceBridge::provider_available('woocommerce')) {
            $this->markTestSkipped('WooCommerce is active in the test env; provider gate path is not exercised.');
        }
        $this->assertFalse($r['ok']);
        $this->assertSame('not_implemented', $r['code']);
        $this->assertSame('no_provider_active', $r['reason'] ?? null);
    }

    public function test_invoke_filter_can_block_action(): void {
        add_filter('dsgo_apps_can_invoke_commerce', static function ($allowed, $action) {
            return $action === 'products.list' ? false : $allowed;
        }, 10, 5);
        $m = $this->manifest_with_endpoints(['products']);
        $r = CommerceBridge::invoke('products.list', [], $m, 0);
        $this->assertFalse($r['ok']);
        $this->assertSame('permission_denied', $r['code']);
        $this->assertSame('invoker_policy', $r['reason'] ?? null);
    }

    public function test_invoke_filter_allows_with_other_action(): void {
        // Same filter, but action doesn't match — should fall through to
        // the provider gate (not_implemented in this test env).
        add_filter('dsgo_apps_can_invoke_commerce', static function ($allowed, $action) {
            return $action === 'products.list' ? false : $allowed;
        }, 10, 5);
        $m = $this->manifest_with_endpoints(['cart']);
        $r = CommerceBridge::invoke('cart.get', [], $m, 0);
        $this->assertFalse($r['ok']);
        $this->assertNotSame('permission_denied', $r['code']);
    }

    public function test_provider_available_woocommerce(): void {
        $expected = class_exists('WooCommerce') || function_exists('WC');
        $this->assertSame($expected, CommerceBridge::provider_available('woocommerce'));
    }

    public function test_provider_available_unknown_returns_false(): void {
        $this->assertFalse(CommerceBridge::provider_available('not-a-real-provider'));
    }
}
