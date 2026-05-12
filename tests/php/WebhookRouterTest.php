<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use DSGo_Apps\WebhookRouter;
use WP_UnitTestCase;

/**
 * Tests for Task 13 of the cron+webhooks plan: WebhookRouter.
 *
 * The router reads every installed app's stored manifest and registers
 * one REST route per `webhooks.endpoints[]` entry — POST handlers under
 * the `/dsgo/v1/webhooks/<app_id>/<endpoint_id>` namespace, each
 * delegating to WebhookHandler::handle().
 *
 * Gating: the entire surface is Pro-only. ProFeatureGate is the sole
 * enforcement point — dispatching a webhook from an unbound route
 * would expose the site to unauthenticated callbacks, so a closed
 * gate means register_all() is a hard no-op.
 *
 * On gate flip (license expires / Pro deactivates), no in-flight
 * routes should remain. The router cleans up by simply not registering
 * them on the next rest_api_init.
 */
final class WebhookRouterTest extends WP_UnitTestCase {

    private const APP_ID = 'webhook-router-test-app';

    public function set_up(): void {
        parent::set_up();
        PostType::register();
        $this->reset_rest_routes();
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        parent::tear_down();
    }

    public function test_register_all_is_noop_when_pro_gate_closed(): void {
        $this->install_app_with_endpoints([
            ['id' => 'stripe-events', 'ability' => 'webhook-router-test-app/handle'],
        ]);
        // Gate closed by default — no filter installed.
        WebhookRouter::register_all();
        $this->assertFalse(
            $this->route_is_registered(self::APP_ID, 'stripe-events'),
            'no webhook routes should register without Pro feature gate',
        );
    }

    public function test_register_all_registers_route_when_gate_open(): void {
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $enabled, string $feature): bool {
            return $feature === 'webhooks' ? true : $enabled;
        }, 10, 2);

        $this->install_app_with_endpoints([
            ['id' => 'stripe-events', 'ability' => 'webhook-router-test-app/handle'],
        ]);

        WebhookRouter::register_all();
        $this->assertTrue($this->route_is_registered(self::APP_ID, 'stripe-events'));
    }

    public function test_register_all_registers_one_route_per_endpoint(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        $this->install_app_with_endpoints([
            ['id' => 'stripe-events', 'ability' => 'webhook-router-test-app/handle'],
            ['id' => 'github-events', 'ability' => 'webhook-router-test-app/handle'],
            ['id' => 'slack-events',  'ability' => 'webhook-router-test-app/handle'],
        ]);

        WebhookRouter::register_all();
        $this->assertTrue($this->route_is_registered(self::APP_ID, 'stripe-events'));
        $this->assertTrue($this->route_is_registered(self::APP_ID, 'github-events'));
        $this->assertTrue($this->route_is_registered(self::APP_ID, 'slack-events'));
    }

    public function test_register_all_skips_apps_without_webhooks_block(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        $this->install_app_without_webhooks();

        WebhookRouter::register_all();
        // No route registered — the app declared no webhooks. We're
        // just asserting the router doesn't crash on absent blocks.
        $this->addToAssertionCount(1);
    }

    public function test_register_all_skips_apps_with_malformed_manifest(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');

        // Insert an app with non-array manifest meta — a stored
        // string or null should be skipped silently rather than
        // surfaced as a 500 on every REST request.
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'malformed-app',
            'post_title'  => 'malformed-app',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', 'this is not a manifest');

        WebhookRouter::register_all();
        $this->assertFalse($this->route_is_registered('malformed-app', 'whatever'));
    }

    public function test_register_single_app_uses_provided_endpoints(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        WebhookRouter::register(self::APP_ID, [
            ['id' => 'stripe-events', 'ability' => self::APP_ID . '/handle'],
        ]);
        $this->assertTrue($this->route_is_registered(self::APP_ID, 'stripe-events'));
    }

    public function test_register_single_app_is_noop_when_gate_closed(): void {
        WebhookRouter::register(self::APP_ID, [
            ['id' => 'stripe-events', 'ability' => self::APP_ID . '/handle'],
        ]);
        $this->assertFalse($this->route_is_registered(self::APP_ID, 'stripe-events'));
    }

    // ===== helpers =====

    private function route_is_registered(string $app_id, string $endpoint_id): bool {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $target = '/dsgo/v1/webhooks/' . $app_id . '/' . $endpoint_id;
        return array_key_exists($target, $routes);
    }

    private function reset_rest_routes(): void {
        // Force the REST server to rebuild on next access so prior tests'
        // registrations don't leak into this one. The simplest way under
        // WP_UnitTestCase: drop the cached server and reinitialize.
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     */
    private function install_app_with_endpoints(array $endpoints): void {
        $manifest_arr = [
            'manifest_version' => 1,
            'id'               => self::APP_ID,
            'name'             => self::APP_ID,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => [], 'run' => ['webhooks']],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => [[
                'name'        => self::APP_ID . '/handle',
                'label'       => 'Handle',
                'description' => 'Handler for the webhook router test.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ]]],
            'secrets'  => [['alias' => 'STRIPE', 'description' => 'Stripe signing secret (test).']],
            'webhooks' => ['endpoints' => array_map(static function (array $e): array {
                return array_merge([
                    'auth' => ['type' => 'hmac-sha256', 'scheme' => 'stripe', 'secret_alias' => 'STRIPE'],
                ], $e);
            }, $endpoints)],
        ];
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => self::APP_ID,
            'post_title'  => self::APP_ID,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest_arr);
    }

    private function install_app_without_webhooks(): void {
        $manifest_arr = [
            'manifest_version' => 1,
            'id'               => 'no-webhooks',
            'name'             => 'no-webhooks',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ];
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'no-webhooks',
            'post_title'  => 'no-webhooks',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest_arr);
    }
}
