<?php
/**
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\DSGoAbilities;
use WP_UnitTestCase;

/**
 * Plugin-scoped abilities registered for the WordPress MCP Adapter to
 * publish to remote AI clients. The category + each ability live behind
 * the wp_abilities_api_init hook; tests invoke register() directly to
 * avoid waiting on the action.
 */
final class DSGoAbilitiesTest extends WP_UnitTestCase {

    public function test_register_creates_the_dsgo_category(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        DSGoAbilities::register();
        $this->assertTrue(wp_has_ability_category(DSGoAbilities::CATEGORY));
    }

    public function test_register_is_idempotent(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        DSGoAbilities::register();
        DSGoAbilities::register();
        $this->assertTrue(wp_has_ability_category(DSGoAbilities::CATEGORY));
    }

    public function test_register_fires_the_pro_extension_hook(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $fired = false;
        add_action('dsgo_apps_register_plugin_abilities', static function () use (&$fired): void {
            $fired = true;
        });
        DSGoAbilities::register();
        $this->assertTrue($fired, 'dsgo_apps_register_plugin_abilities must fire so Pro can attach.');
    }

    public function test_list_apps_is_registered_with_dsgo_category(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        DSGoAbilities::register();
        $this->assertTrue(wp_has_ability('dsgo/list-apps'));
        $ability = wp_get_ability('dsgo/list-apps');
        $this->assertNotNull($ability);
        $this->assertSame(DSGoAbilities::CATEGORY, $ability->get_category());
        $this->assertIsArray($ability->get_output_schema());
    }

    public function test_list_apps_returns_empty_array_on_fresh_site(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/list-apps')->execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('apps', $result);
        $this->assertSame([], $result['apps']);
    }

    public function test_list_apps_summarizes_installed_apps_from_manifest_meta(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $post_id = self::factory()->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'sample-app',
            'post_title'  => 'Sample App',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'id'      => 'sample-app',
            'name'    => 'Sample App',
            'version' => '1.2.3',
            'routes'  => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/about', 'file' => 'about.html'],
            ],
        ]);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/list-apps')->execute([]);

        $this->assertCount(1, $result['apps']);
        $row = $result['apps'][0];
        $this->assertSame('sample-app', $row['app_id']);
        $this->assertSame('sample-app', $row['slug']);
        $this->assertSame('Sample App', $row['title']);
        $this->assertSame('1.2.3', $row['version']);
        // Multiple static routes alone don't make an app "dynamic"; only a
        // populated dataset.source does. Mirrors AdminPage::manifest_has_dynamic_route.
        $this->assertFalse($row['has_dynamic_routes']);
        $this->assertNotEmpty($row['install_date']);
    }

    public function test_list_apps_marks_apps_with_dataset_source_as_dynamic(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $post_id = self::factory()->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'dynamic-app',
            'post_title'  => 'Dynamic App',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'id'      => 'dynamic-app',
            'name'    => 'Dynamic App',
            'version' => '0.1.0',
            'routes'  => [
                [
                    'path'    => '/posts/:slug',
                    'file'    => 'post.html',
                    'dataset' => ['source' => 'wp:posts', 'id_field' => 'slug'],
                ],
            ],
        ]);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/list-apps')->execute([]);

        $row = self::row_for_app($result['apps'], 'dynamic-app');
        $this->assertNotNull($row);
        $this->assertTrue($row['has_dynamic_routes']);
    }

    /**
     * @param list<array<string,mixed>> $apps
     * @return array<string,mixed>|null
     */
    private static function row_for_app(array $apps, string $app_id): ?array {
        foreach ($apps as $row) {
            if (($row['app_id'] ?? null) === $app_id) {
                return $row;
            }
        }
        return null;
    }

    public function test_list_apps_denies_anonymous_callers(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        wp_set_current_user(0);
        DSGoAbilities::register();
        $this->assertFalse(wp_get_ability('dsgo/list-apps')->check_permissions(null));
    }

    public function test_get_app_returns_manifest_excerpt(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $post_id = self::factory()->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'sample-app',
            'post_title'  => 'Sample',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'id'      => 'sample-app',
            'name'    => 'Sample',
            'version' => '0.1.0',
            'permissions' => ['read' => [], 'write' => []],
            'abilities'   => ['publishes' => []],
            'display'     => ['default' => 'page'],
            'routes'      => [['path' => '/', 'file' => 'index.html']],
        ]);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/get-app')->execute(['app_id' => 'sample-app']);

        $this->assertIsArray($result);
        $this->assertSame('sample-app', $result['app_id']);
        $this->assertSame('Sample', $result['title']);
        $this->assertArrayHasKey('manifest_excerpt', $result);
        $this->assertSame(['read' => [], 'write' => []], $result['manifest_excerpt']['permissions']);
        $this->assertSame(['default' => 'page'], $result['manifest_excerpt']['display']);
        $this->assertSame(1, $result['manifest_excerpt']['routes_count']);
        $this->assertSame([], $result['manifest_excerpt']['abilities_publishes']);
    }

    public function test_get_app_returns_app_not_found_for_unknown_id(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/get-app')->execute(['app_id' => 'no-such-app']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('app_not_found', $result->get_error_code());
    }

    public function test_get_app_rejects_missing_app_id_via_input_schema(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        // WP_Ability validates input against `input_schema` before invoking
        // the execute_callback, so a missing required `app_id` is rejected
        // with the framework's own error code (not our app_not_found / etc).
        $result = wp_get_ability('dsgo/get-app')->execute([]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_input', $result->get_error_code());
    }

    public function test_list_templates_is_public(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        wp_set_current_user(0);
        DSGoAbilities::register();
        $this->assertTrue(wp_get_ability('dsgo/list-templates')->check_permissions(null));
    }

    public function test_delete_app_requires_manage_options(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        DSGoAbilities::register();
        $this->assertFalse(wp_get_ability('dsgo/delete-app')->check_permissions(null));
    }

    public function test_delete_app_rejects_without_confirm(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/delete-app')->execute([
            'app_id'  => 'sample-app',
            'confirm' => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('delete_not_confirmed', $result->get_error_code());
    }

    public function test_delete_app_returns_app_not_found_when_missing(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/delete-app')->execute([
            'app_id'  => 'never-installed',
            'confirm' => true,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('app_not_found', $result->get_error_code());
    }

    public function test_delete_app_uninstalls_existing_app(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $post_id = self::factory()->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'to-delete',
            'post_title'  => 'To Delete',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'id'      => 'to-delete',
            'name'    => 'To Delete',
            'version' => '0.1.0',
        ]);

        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/delete-app')->execute([
            'app_id'  => 'to-delete',
            'confirm' => true,
        ]);

        $this->assertSame(['ok' => true, 'app_id' => 'to-delete'], $result);
        $this->assertNull(get_page_by_path('to-delete', OBJECT, \DSGo_Apps\PostType::SLUG));
    }

    public function test_list_templates_returns_pro_required_when_pro_absent(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        DSGoAbilities::register();
        $result = wp_get_ability('dsgo/list-templates')->execute([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('templates', $result);
        $this->assertArrayHasKey('pro_required', $result);
        if (class_exists('\\DSGo_Apps_Pro\\Harness_Templates')) {
            $this->assertFalse($result['pro_required']);
            $this->assertIsArray($result['templates']);
            foreach ($result['templates'] as $row) {
                $this->assertArrayHasKey('slug', $row);
                $this->assertArrayHasKey('name', $row);
            }
        } else {
            $this->assertTrue($result['pro_required']);
            $this->assertSame([], $result['templates']);
        }
    }

    public function test_list_apps_requires_manage_options(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        // Mirrors the manage_options gate the existing GET /dsgo/v1/apps
        // route enforces; an MCP caller should not be able to enumerate
        // installed apps from a subscriber session.
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        DSGoAbilities::register();
        $this->assertFalse(wp_get_ability('dsgo/list-apps')->check_permissions(null));
    }

    public function test_get_app_requires_manage_options(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        DSGoAbilities::register();
        $this->assertFalse(wp_get_ability('dsgo/get-app')->check_permissions(null));
    }

    public function test_riff_disabled_stubs_are_registered_for_discovery(): void {
        if (!function_exists('wp_register_ability')) {
            $this->markTestSkipped('Abilities API not loaded; requires WP 7.0+.');
        }
        // Lite must register stubs so a Lite-only site still surfaces the
        // Pro abilities to MCP clients (the upgrade-discovery contract in
        // /connect and the spec). Pro replaces them via wp_unregister.
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        DSGoAbilities::register();
        $this->assertTrue(wp_has_ability('dsgo/generate-app'));
        $this->assertTrue(wp_has_ability('dsgo/install-app'));

        // The stub returns the documented named error code.
        $gen = wp_get_ability('dsgo/generate-app')->execute(['prompt' => 'anything']);
        $this->assertInstanceOf(\WP_Error::class, $gen);
        $this->assertSame('riff_feature_disabled', $gen->get_error_code());

        $inst = wp_get_ability('dsgo/install-app')->execute([
            'token' => 'draft_' . str_repeat('0', 32),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $inst);
        $this->assertSame('riff_feature_disabled', $inst->get_error_code());
    }
}
