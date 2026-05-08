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

    // --- shape_product / shape_price normalize stdClass ------------------

    public function test_shape_product_handles_object_nested_fields(): void {
        // WC's Store API returns nested fields (images, prices) as stdClass
        // objects when invoked via rest_do_request from inside another REST
        // request. Without normalization, is_array() checks skipped them all
        // and the bridge returned `images: []` / empty `price`.
        $raw = [
            'id'       => 42,
            'name'     => 'Stub Mug',
            'slug'     => 'stub-mug',
            'images'   => [
                (object) [
                    'id'        => 99,
                    'src'       => 'https://example.com/full.jpg',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'alt'       => 'Mug photo',
                    'srcset'    => 'https://example.com/full.jpg 2x',
                ],
            ],
            'prices'   => (object) [
                'price'                  => '1200',
                'regular_price'          => '1500',
                'sale_price'             => '1200',
                'currency_code'          => 'USD',
                'currency_minor_unit'    => 2,
                'price_range'            => null,
            ],
            'is_in_stock'   => true,
            'is_purchasable'=> true,
        ];

        $shaped = self::call_private('shape_product', [$raw]);

        $this->assertCount(1, $shaped['images'], 'image objects should not be skipped');
        $this->assertSame(99, $shaped['images'][0]['id']);
        $this->assertSame('https://example.com/full.jpg', $shaped['images'][0]['src']);
        $this->assertSame('https://example.com/thumb.jpg', $shaped['images'][0]['thumbnail']);
        $this->assertSame('Mug photo', $shaped['images'][0]['alt']);

        $this->assertSame('1200', $shaped['price']['amount']);
        $this->assertSame('1500', $shaped['price']['regular']);
        $this->assertSame('USD', $shaped['price']['currency']);
        $this->assertSame(2, $shaped['price']['minor_unit']);
    }

    public function test_shape_product_handles_array_nested_fields(): void {
        // Backward-compat: if a different code path returns plain arrays
        // (e.g. tests, custom stubs), the shape function should still work.
        $raw = [
            'id' => 7, 'name' => 'Plain', 'slug' => 'plain',
            'images' => [['id' => 1, 'src' => 'a.jpg', 'thumbnail' => 'a-t.jpg', 'alt' => 'A']],
            'prices' => ['price' => '500', 'currency_code' => 'USD'],
        ];
        $shaped = self::call_private('shape_product', [$raw]);
        $this->assertSame('a.jpg', $shaped['images'][0]['src']);
        $this->assertSame('500', $shaped['price']['amount']);
    }

    public function test_shape_price_handles_object_with_price_range_object(): void {
        $prices = (object) [
            'price' => '0', 'currency_code' => 'USD', 'currency_minor_unit' => 2,
            'price_range' => (object) ['min_amount' => '500', 'max_amount' => '2500'],
        ];
        $shaped = self::call_private('shape_price', [$prices]);
        $this->assertSame('500', $shaped['min']);
        $this->assertSame('2500', $shaped['max']);
    }

    // --- Store API nonce on cart writes ---------------------------------

    public function test_store_api_request_attaches_nonce_to_cart_writes(): void {
        $captured = $this->capture_rest_dispatch_request(function (): void {
            self::call_private('store_api_request', [
                'POST', '/wc/store/v1/cart/add-item', [], ['id' => 1, 'quantity' => 1],
                $this->manifest_with_endpoints(['cart']),
                0,
            ]);
        });

        $this->assertNotNull($captured, 'rest_pre_dispatch should have fired');
        $nonce = $captured->get_header('Nonce');
        $this->assertNotEmpty($nonce, 'cart write must carry a Nonce header for WC Store API');
        $this->assertNotFalse(wp_verify_nonce($nonce, 'wc_store_api'), 'nonce must verify against wc_store_api action');
    }

    public function test_store_api_request_does_not_attach_nonce_to_reads(): void {
        $captured = $this->capture_rest_dispatch_request(function (): void {
            self::call_private('store_api_request', [
                'GET', '/wc/store/v1/products', ['per_page' => 1], null,
                $this->manifest_with_endpoints(['products']),
                0,
            ]);
        });

        $this->assertNotNull($captured);
        $this->assertSame('', (string) $captured->get_header('Nonce'),
            'GET reads must not carry a forged nonce — only cart writes need it');
    }

    public function test_store_api_request_does_not_attach_nonce_to_non_cart_writes(): void {
        // POST /wc/store/v1/cart (the cart root) is a read; only sub-paths
        // (cart/add-item, cart/update-item, cart/remove-item) are writes.
        // Synthesizing a nonce on every POST would be wrong if WC ever
        // adds a non-mutating POST endpoint outside cart/*.
        $captured = $this->capture_rest_dispatch_request(function (): void {
            self::call_private('store_api_request', [
                'POST', '/wc/store/v1/checkout', [], [],
                $this->manifest_with_endpoints(['checkout']),
                0,
            ]);
        });
        $this->assertSame('', (string) $captured?->get_header('Nonce') ?? '');
    }

    /**
     * Intercept rest_do_request via rest_pre_dispatch and capture the
     * WP_REST_Request object so tests can assert on its headers/params
     * without actually invoking the (absent) WC Store API.
     */
    private function capture_rest_dispatch_request(callable $action): ?\WP_REST_Request {
        $captured = null;
        $filter = function ($result, $server, \WP_REST_Request $req) use (&$captured) {
            $captured = $req;
            // Short-circuit: return a non-null response so rest_do_request
            // doesn't try to actually serve the route.
            return new \WP_REST_Response(['stubbed' => true], 200);
        };
        add_filter('rest_pre_dispatch', $filter, 10, 3);
        try {
            $action();
        } finally {
            remove_filter('rest_pre_dispatch', $filter, 10);
        }
        return $captured;
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private static function call_private(string $method, array $args) {
        $rc = new \ReflectionClass(CommerceBridge::class);
        $m  = $rc->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }
}
