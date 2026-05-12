<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AsyncWebhookHandler;
use DSGo_Apps\PostType;
use DSGo_Apps\Secret_Vault;
use DSGo_Apps\WebhookHandler;
use DSGo_Apps\WebhookIdempotency;
use DSGo_Apps\WebhookLog;
use DSGo_Apps\WebhookQueue;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests for Task 12 of the cron+webhooks plan: WebhookHandler.
 *
 * The 10-step pipeline:
 *   1. Load endpoint config from manifest                 → 404 if missing.
 *   2. Rate-limit check                                   → 429 if exhausted.
 *   3. Build safe headers + read raw body.
 *   4. Verify auth                                        → 401 on mismatch,
 *                                                          503 on missing secret.
 *   5. Idempotency check (when idempotency_header present)
 *                                                         → 200 idempotent:true
 *                                                            on duplicate.
 *   6. Async path: enqueue + schedule + return 200 queued:true
 *                                                         (no idempotency set
 *                                                          here — async run()
 *                                                          sets it on success).
 *   7. Sync path: resolve ability                         → 404 missing,
 *                                                          503 inactive.
 *   8. Invoke ability.
 *   9. Set idempotency transient (sync success only).
 *  10. Write WebhookLog row + return.
 */
final class WebhookHandlerTest extends WP_UnitTestCase {

    private const APP_ID      = 'myapp';
    private const ENDPOINT_ID = 'stripe-events';
    private const ALIAS       = 'STRIPE_SIGNING_SECRET';
    private const SECRET      = 'whsec_test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function set_up(): void {
        parent::set_up();
        WebhookLog::create_table();
        WebhookQueue::create_table();
        PostType::register();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_log");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_queue");
        Secret_Vault::set(self::APP_ID, self::ALIAS, self::SECRET);
        _set_cron_array([]);
    }

    public function tear_down(): void {
        Secret_Vault::delete_all(self::APP_ID);
        parent::tear_down();
    }

    public function test_unknown_endpoint_returns_404(): void {
        $this->install_app([
            ['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle'],
        ]);
        $response = WebhookHandler::handle(
            $this->stripe_request('{}', time()),
            self::APP_ID,
            'unknown-endpoint',
        );
        $this->assertSame(404, $response->get_status());
    }

    public function test_missing_app_returns_404(): void {
        $response = WebhookHandler::handle(
            $this->stripe_request('{}', time()),
            'no-such-app',
            self::ENDPOINT_ID,
        );
        $this->assertSame(404, $response->get_status());
    }

    public function test_rate_limit_exceeded_returns_429(): void {
        $this->install_app([[
            'id'                    => self::ENDPOINT_ID,
            'ability'               => 'myapp/handle',
            'rate_limit_per_minute' => 1,
        ]], fn () => ['ok' => true]);

        // First request succeeds, consumes the entire quota.
        $first  = WebhookHandler::handle($this->signed_request('{}'), self::APP_ID, self::ENDPOINT_ID);
        $this->assertSame(200, $first->get_status());

        // Second one is rate-limited.
        $second = WebhookHandler::handle($this->signed_request('{}'), self::APP_ID, self::ENDPOINT_ID);
        $this->assertSame(429, $second->get_status());
        $headers = $second->get_headers();
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertGreaterThan(0, (int) $headers['Retry-After']);
    }

    public function test_secret_not_configured_returns_503(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']]);
        Secret_Vault::delete_all(self::APP_ID);
        $response = WebhookHandler::handle(
            $this->signed_request('{}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(503, $response->get_status());
        $this->assertSame('webhook_secret_not_set', $response->get_data()['error_code']);
    }

    public function test_auth_failure_returns_401(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']]);
        $req = $this->stripe_request('{}', time(), 'wrong_signature');
        $response = WebhookHandler::handle($req, self::APP_ID, self::ENDPOINT_ID);
        $this->assertSame(401, $response->get_status());
        $this->assertSame('webhook_auth_failed', $response->get_data()['error_code']);
    }

    public function test_idempotent_duplicate_returns_200_idempotent(): void {
        $this->install_app([[
            'id'                 => self::ENDPOINT_ID,
            'ability'            => 'myapp/handle',
            'idempotency_header' => 'Stripe-Event-Id',
        ]], fn () => ['ok' => true]);

        $body = '{"id":"evt_dup"}';
        // First call seeds the idempotency cache via the success path.
        $first = WebhookHandler::handle(
            $this->signed_request_with_idem($body, 'evt_dup'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(200, $first->get_status());
        $this->assertArrayNotHasKey('idempotent', $first->get_data());

        // Second call hits the cache.
        $second = WebhookHandler::handle(
            $this->signed_request_with_idem($body, 'evt_dup'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(200, $second->get_status());
        $this->assertTrue($second->get_data()['idempotent']);
    }

    public function test_async_endpoint_returns_queued_and_enqueues_row(): void {
        $this->install_app([[
            'id'      => self::ENDPOINT_ID,
            'ability' => 'myapp/handle',
            'async'   => true,
        ]], fn () => ['ok' => true]);

        $response = WebhookHandler::handle(
            $this->signed_request('{"id":"evt_async"}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['queued']);

        // Queue row should exist; idempotency should NOT be set
        // (async sets it inside AsyncWebhookHandler::run on success).
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dsgo_apps_webhook_queue");
        $this->assertSame(1, $count);
        $this->assertFalse(WebhookIdempotency::check(self::APP_ID, self::ENDPOINT_ID, 'evt_async'));
    }

    public function test_async_schedules_single_event(): void {
        $this->install_app([[
            'id'      => self::ENDPOINT_ID,
            'ability' => 'myapp/handle',
            'async'   => true,
        ]], fn () => ['ok' => true]);

        WebhookHandler::handle(
            $this->signed_request('{"id":"evt_sched"}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        // wp_next_scheduled needs the queue row id as args — read it
        // off the queue table.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row_id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}dsgo_apps_webhook_queue LIMIT 1");
        $this->assertGreaterThan(0, $row_id);
        $this->assertNotFalse(wp_next_scheduled(AsyncWebhookHandler::ASYNC_HOOK, [$row_id]));
    }

    public function test_ability_inactive_returns_503(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']],
            fn () => new \WP_Error(
                'execute_php_class_not_loadable',
                'Companion plugin not installed: class Acme\\Plugin\\Nonexistent is not loadable.',
            ),
        );
        $response = WebhookHandler::handle(
            $this->signed_request('{}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(503, $response->get_status());
        $this->assertSame('execute_php_class_not_loadable', $response->get_data()['error_code']);
    }

    public function test_ability_wp_error_returns_500(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']],
            fn () => new \WP_Error('upstream', 'service unavailable'),
        );
        $response = WebhookHandler::handle(
            $this->signed_request('{}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(500, $response->get_status());
        $this->assertSame('webhook_ability_failed', $response->get_data()['error_code']);
    }

    public function test_ability_exception_returns_500(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']],
            function () { throw new \RuntimeException('boom'); },
        );
        $response = WebhookHandler::handle(
            $this->signed_request('{}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(500, $response->get_status());
    }

    public function test_sync_success_writes_log_and_sets_idempotency(): void {
        $this->install_app([[
            'id'                 => self::ENDPOINT_ID,
            'ability'            => 'myapp/handle',
            'idempotency_header' => 'Stripe-Event-Id',
        ]], fn () => ['ok' => true]);

        $response = WebhookHandler::handle(
            $this->signed_request_with_idem('{}', 'evt_log'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $this->assertSame(200, $response->get_status());

        // Log row written, status='ok', async=0.
        $rows = WebhookLog::query(self::APP_ID);
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(0, (int) $rows[0]['async']);

        // Idempotency transient seeded.
        $this->assertTrue(WebhookIdempotency::check(self::APP_ID, self::ENDPOINT_ID, 'evt_log'));
    }

    public function test_auth_failure_writes_log_row(): void {
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']]);
        WebhookHandler::handle(
            $this->stripe_request('{}', time(), 'wrong'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );
        $rows = WebhookLog::query(self::APP_ID);
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame(401, (int) $rows[0]['http_status']);
        $this->assertSame('webhook_auth_failed', $rows[0]['error_code']);
    }

    public function test_safe_headers_strip_authorization_and_signature_from_input(): void {
        $captured = null;
        $this->install_app([['id' => self::ENDPOINT_ID, 'ability' => 'myapp/handle']],
            function ($input) use (&$captured) {
                $captured = $input;
                return ['ok' => true];
            },
        );

        WebhookHandler::handle(
            $this->signed_request('{}'),
            self::APP_ID,
            self::ENDPOINT_ID,
        );

        $this->assertNotNull($captured);
        $this->assertIsArray($captured['headers']);
        // Stripe-Signature must NOT be passed to the ability — it's the
        // auth credential, not part of the event payload.
        foreach (array_keys($captured['headers']) as $k) {
            $lower = strtolower($k);
            $this->assertNotSame('authorization',    $lower);
            $this->assertNotSame('stripe-signature', $lower);
        }
    }

    // ===== helpers =====

    /**
     * Install a dsgo_app post with the given endpoints, optionally
     * registering an ability for `myapp/handle` with $execute.
     *
     * @param array<int, array<string, mixed>> $endpoints
     */
    private function install_app(array $endpoints, ?callable $execute = null): void {
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
                'name'        => 'myapp/handle',
                'label'       => 'Handle',
                'description' => 'Handler for the webhook handler test.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ]]],
            'secrets'  => [['alias' => self::ALIAS, 'description' => 'Stripe signing secret (test).']],
            'webhooks' => ['endpoints' => array_map(static function (array $e): array {
                $base = [
                    'auth' => ['type' => 'hmac-sha256', 'scheme' => 'stripe', 'secret_alias' => self::ALIAS],
                ];
                return array_merge($base, $e);
            }, $endpoints)],
        ];
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => self::APP_ID,
            'post_title'  => self::APP_ID,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest_arr);

        if ($execute !== null) {
            global $wp_current_filter;
            if (function_exists('wp_register_ability_category') && function_exists('wp_has_ability_category')) {
                $wp_current_filter[] = 'wp_abilities_api_categories_init';
                try {
                    if (!wp_has_ability_category('content')) {
                        wp_register_ability_category('content', ['label' => 'Content', 'description' => 'Test.']);
                    }
                } finally {
                    array_pop($wp_current_filter);
                }
            }
            $wp_current_filter[] = 'wp_abilities_api_init';
            try {
                if (function_exists('wp_has_ability') && wp_has_ability('myapp/handle')) {
                    wp_unregister_ability('myapp/handle');
                }
                wp_register_ability('myapp/handle', [
                    'label'               => 'Handle webhook',
                    'description'         => 'Test handler.',
                    'category'            => 'content',
                    'permission_callback' => '__return_true',
                    'execute_callback'    => $execute,
                    'input_schema'        => ['type' => 'object', 'additionalProperties' => true],
                ]);
            } finally {
                array_pop($wp_current_filter);
            }
        }
    }

    private function stripe_request(string $body, int $ts, ?string $override_sig = null): WP_REST_Request {
        $sig = $override_sig ?? hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);
        $req = new WP_REST_Request('POST', '/dsgo/v1/webhooks/' . self::APP_ID . '/' . self::ENDPOINT_ID);
        $req->set_body($body);
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe-signature', "t={$ts},v1={$sig}");
        return $req;
    }

    private function signed_request(string $body): WP_REST_Request {
        return $this->stripe_request($body, time());
    }

    private function signed_request_with_idem(string $body, string $event_id): WP_REST_Request {
        $req = $this->signed_request($body);
        $req->set_header('stripe-event-id', $event_id);
        return $req;
    }
}
