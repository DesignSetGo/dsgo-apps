<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AbilitiesBridge;
use DSGo_Apps\Manifest;
use DSGo_Apps\Permission;
use WP_UnitTestCase;

class AbilitiesBridgeTest extends WP_UnitTestCase {

    private array $registered = [];
    private static bool $category_registered = false;

    public static function set_up_before_class(): void {
        parent::set_up_before_class();
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        if (!function_exists('wp_has_ability_category') || !wp_has_ability_category('test')) {
            global $wp_current_filter;
            $wp_current_filter[] = 'wp_abilities_api_categories_init';
            wp_register_ability_category('test', [
                'label'       => 'Test',
                'description' => 'Test ability category',
            ]);
            array_pop($wp_current_filter);
            self::$category_registered = true;
        }
    }

    public static function tear_down_after_class(): void {
        if (self::$category_registered && function_exists('wp_unregister_ability_category')) {
            wp_unregister_ability_category('test');
            self::$category_registered = false;
        }
        parent::tear_down_after_class();
    }

    public function tear_down(): void {
        foreach ($this->registered as $name) {
            if (function_exists('wp_unregister_ability')) {
                wp_unregister_ability($name);
            }
        }
        $this->registered = [];
        remove_all_filters('dsgo_apps_can_invoke_ability');
        parent::tear_down();
    }

    private function register_ability(string $name, callable $execute, ?callable $permission = null, ?array $input_schema = null): void {
        global $wp_current_filter;
        // wp_register_ability() enforces doing_action('wp_abilities_api_init').
        // Fake the action context so test setup works outside the hook.
        $wp_current_filter[] = 'wp_abilities_api_init';
        $args = [
            'label'              => $name,
            'description'        => 'test ability ' . $name,
            'category'           => 'test',
            'execute_callback'   => $execute,
            'permission_callback' => $permission ?? static fn () => true,
        ];
        if ($input_schema !== null) {
            $args['input_schema'] = $input_schema;
        }
        wp_register_ability($name, $args);
        array_pop($wp_current_filter);
        $this->registered[] = $name;
    }

    private function manifest_with_consumes(array $patterns): Manifest {
        return Manifest::validate([
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['abilities'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities' => ['consumes' => $patterns],
        ]);
    }

    // --- pattern_matches ------------------------------------------------

    public function test_pattern_matches_exact(): void {
        $this->assertTrue(AbilitiesBridge::pattern_matches('yoast/analyze-page-seo', 'yoast/analyze-page-seo'));
        $this->assertFalse(AbilitiesBridge::pattern_matches('yoast/analyze-page-seo', 'yoast/other'));
    }

    public function test_pattern_matches_namespace_wildcard(): void {
        $this->assertTrue(AbilitiesBridge::pattern_matches('yoast/*', 'yoast/x'));
        $this->assertTrue(AbilitiesBridge::pattern_matches('yoast/*', 'yoast/list-products'));
        $this->assertFalse(AbilitiesBridge::pattern_matches('yoast/*', 'other/x'));
        $this->assertFalse(AbilitiesBridge::pattern_matches('yoast/*', 'yoast'));
    }

    public function test_pattern_matches_prefix_wildcard(): void {
        $this->assertTrue(AbilitiesBridge::pattern_matches('woocommerce/list-*', 'woocommerce/list-products'));
        $this->assertTrue(AbilitiesBridge::pattern_matches('woocommerce/list-*', 'woocommerce/list-orders'));
        $this->assertFalse(AbilitiesBridge::pattern_matches('woocommerce/list-*', 'woocommerce/get-product'));
        $this->assertFalse(AbilitiesBridge::pattern_matches('woocommerce/list-*', 'other/list-x'));
    }

    public function test_pattern_matches_digit_leading_namespace(): void {
        $this->assertTrue(AbilitiesBridge::pattern_matches('9to5/foo', '9to5/foo'));
    }

    // --- list_for_app ---------------------------------------------------

    public function test_list_for_app_filters_to_consumes_patterns(): void {
        $this->register_ability('yoast/analyze-page-seo', static fn () => null);
        $this->register_ability('yoast/list-redirects', static fn () => null);
        $this->register_ability('woocommerce/list-products', static fn () => null);
        $manifest = $this->manifest_with_consumes(['yoast/*']);

        $list = AbilitiesBridge::list_for_app($manifest, 0);
        $names = array_column($list, 'name');
        sort($names);
        $this->assertSame(['yoast/analyze-page-seo', 'yoast/list-redirects'], $names);
    }

    public function test_list_for_app_drops_abilities_failing_permission_callback(): void {
        $this->register_ability('test/allowed', static fn () => null, static fn () => true);
        $this->register_ability('test/blocked', static fn () => null, static fn () => false);
        $manifest = $this->manifest_with_consumes(['test/*']);

        $list = AbilitiesBridge::list_for_app($manifest, 0);
        $this->assertCount(1, $list);
        $this->assertSame('test/allowed', $list[0]['name']);
    }

    public function test_list_for_app_drops_when_invoker_filter_returns_false(): void {
        $this->register_ability('test/x', static fn () => null);
        add_filter('dsgo_apps_can_invoke_ability', static fn () => false, 10, 5);
        $manifest = $this->manifest_with_consumes(['test/*']);

        $list = AbilitiesBridge::list_for_app($manifest, 0);
        $this->assertSame([], $list);
    }

    public function test_list_for_app_returns_empty_for_empty_consumes(): void {
        $this->register_ability('test/x', static fn () => null);
        $manifest = $this->manifest_with_consumes([]);
        $this->assertSame([], AbilitiesBridge::list_for_app($manifest, 0));
    }

    public function test_list_for_app_descriptor_shape(): void {
        $this->register_ability('test/echo', static fn ($input) => $input);
        $manifest = $this->manifest_with_consumes(['test/*']);

        $list = AbilitiesBridge::list_for_app($manifest, 0);
        $this->assertCount(1, $list);
        $entry = $list[0];
        $this->assertSame('test/echo', $entry['name']);
        $this->assertSame('test/echo', $entry['label']);
        $this->assertSame('test ability test/echo', $entry['description']);
        $this->assertSame('test', $entry['category']);
        $this->assertArrayHasKey('input_schema', $entry);
        $this->assertArrayHasKey('output_schema', $entry);
        $this->assertArrayHasKey('annotations', $entry);
    }

    // --- invoke ---------------------------------------------------------

    public function test_invoke_unmatched_returns_permission_denied(): void {
        $this->register_ability('test/x', static fn () => null);
        $manifest = $this->manifest_with_consumes(['other/x']);
        $result = AbilitiesBridge::invoke('test/x', [], $manifest, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
        $this->assertSame('not_in_consumes', $result['reason']);
    }

    public function test_invoke_unknown_returns_not_found(): void {
        $manifest = $this->manifest_with_consumes(['test/x']);
        $result = AbilitiesBridge::invoke('test/x', [], $manifest, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['code']);
    }

    public function test_invoke_invoker_filter_blocks(): void {
        $this->register_ability('test/x', static fn () => 'ran');
        add_filter('dsgo_apps_can_invoke_ability', static fn () => false, 10, 5);
        $manifest = $this->manifest_with_consumes(['test/*']);

        $result = AbilitiesBridge::invoke('test/x', [], $manifest, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
        $this->assertSame('invoker_policy', $result['reason']);
    }

    public function test_invoke_capability_denied_maps_to_permission_denied(): void {
        $this->register_ability(
            'test/x',
            static fn () => 'ran',
            static fn () => new \WP_Error('ability_invalid_permissions', 'no perm'),
        );
        $manifest = $this->manifest_with_consumes(['test/*']);
        // WP_Ability::execute() calls _doing_it_wrong when permission_callback returns WP_Error.
        $this->setExpectedIncorrectUsage('WP_Ability::execute');

        $result = AbilitiesBridge::invoke('test/x', [], $manifest, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
        $this->assertSame('capability_denied', $result['reason']);
    }

    public function test_invoke_happy_path_returns_data(): void {
        // Provide an input_schema so WP_Ability::execute() accepts and passes the input through.
        $this->register_ability(
            'test/echo',
            static fn ($input) => ['echo' => $input],
            null,
            ['type' => 'object'],
        );
        $manifest = $this->manifest_with_consumes(['test/*']);

        $result = AbilitiesBridge::invoke('test/echo', ['hello' => 'world'], $manifest, 0);
        $this->assertTrue($result['ok']);
        $this->assertSame(['echo' => ['hello' => 'world']], $result['data']);
    }

    public function test_invoke_wp_error_other_code_maps_to_internal_error(): void {
        $this->register_ability(
            'test/x',
            static fn () => new \WP_Error('something_broke', 'broken'),
        );
        $manifest = $this->manifest_with_consumes(['test/*']);

        $result = AbilitiesBridge::invoke('test/x', [], $manifest, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('internal_error', $result['code']);
        $this->assertSame('something_broke', $result['wp_error_code']);
    }

    public function test_invoke_filter_receives_expected_args(): void {
        $this->register_ability('test/x', static fn () => 'ok');
        $captured = [];
        add_filter('dsgo_apps_can_invoke_ability', static function ($allow, $name, $args, $app_id, $user_id) use (&$captured) {
            $captured[] = [$allow, $name, $args, $app_id, $user_id];
            return $allow;
        }, 10, 5);
        $manifest = $this->manifest_with_consumes(['test/*']);

        AbilitiesBridge::invoke('test/x', ['k' => 'v'], $manifest, 42);
        $this->assertCount(1, $captured);
        $this->assertSame(true, $captured[0][0]);
        $this->assertSame('test/x', $captured[0][1]);
        $this->assertSame(['k' => 'v'], $captured[0][2]);
        $this->assertSame('sample', $captured[0][3]);
        $this->assertSame(42, $captured[0][4]);
    }
}
