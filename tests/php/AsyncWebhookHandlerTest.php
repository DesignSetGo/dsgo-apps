<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AsyncWebhookHandler;
use DSGo_Apps\PostType;
use DSGo_Apps\Secret_Vault;
use DSGo_Apps\WebhookLog;
use DSGo_Apps\WebhookQueue;
use WP_UnitTestCase;

/**
 * Tests for Task 11 of the cron+webhooks plan: AsyncWebhookHandler.
 *
 * Two surfaces:
 *
 *   - enqueue($app_id, $endpoint_id, $idempotency_key, $body,
 *     $headers_json) — encrypts body + headers with the site's
 *     sodium key, inserts a queue row, returns the row id. Caller
 *     (WebhookHandler in Task 12) is expected to wp_schedule_single_event
 *     the row id onto `dsgo_apps_webhook_async`.
 *
 *   - run($row_id) — pulls the row, decrypts, resolves the manifest's
 *     endpoint config to find the ability, invokes it. On success,
 *     deletes the row and writes a WebhookLog row with status='ok'.
 *     On WP_Error or thrown exception: increments attempts; if under
 *     the 3-attempt cap, schedules a single-event retry 5 minutes out;
 *     otherwise marks the row status='failed' and writes a final
 *     WebhookLog row with status='error'.
 */
final class AsyncWebhookHandlerTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        WebhookQueue::create_table();
        WebhookLog::create_table();
        PostType::register();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_queue");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_log");
        // Reset any previously scheduled single events.
        _set_cron_array([]);
    }

    public function test_enqueue_inserts_row_and_returns_id(): void {
        $id = AsyncWebhookHandler::enqueue(
            'myapp',
            'stripe-events',
            'evt_123',
            '{"amount":100}',
            '{"Content-Type":"application/json"}',
        );
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertNotNull(WebhookQueue::get($id));
    }

    public function test_enqueue_stores_encrypted_body_not_plaintext(): void {
        // The body literal `100` must NOT appear in the stored bytes —
        // sodium ciphertext should obscure it. If you can grep the
        // plaintext out of the row, encryption isn't happening.
        $id  = AsyncWebhookHandler::enqueue('myapp', 'stripe', null, '{"amount":100}', '{}');
        $row = WebhookQueue::get($id);
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('amount', $row['encrypted_body']);
        $this->assertStringNotContainsString('100',    $row['encrypted_body']);
    }

    public function test_enqueue_round_trips_through_decryption(): void {
        // Exercise the symmetric path: enqueue, then manually decrypt
        // using the same key the run() path will use. Proves the
        // encryption format is what run() expects.
        $body    = '{"event":"checkout.session.completed","id":"evt_test"}';
        $headers = '{"Stripe-Signature":"t=12345,v1=abc"}';
        $id      = AsyncWebhookHandler::enqueue('myapp', 'stripe', 'evt_test', $body, $headers);
        $row     = WebhookQueue::get($id);

        $key   = Secret_Vault::encryption_key();
        $nlen  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $body_plain = sodium_crypto_secretbox_open(
            substr($row['encrypted_body'], $nlen),
            substr($row['encrypted_body'], 0, $nlen),
            $key,
        );
        $headers_plain = sodium_crypto_secretbox_open(
            substr($row['encrypted_headers'], $nlen),
            substr($row['encrypted_headers'], 0, $nlen),
            $key,
        );
        $this->assertSame($body, $body_plain);
        $this->assertSame($headers, $headers_plain);
    }

    public function test_run_decrypts_and_invokes_ability(): void {
        $invoked_with = null;
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', function ($input) use (&$invoked_with) {
            $invoked_with = $input;
            return ['ok' => true];
        });
        $id = AsyncWebhookHandler::enqueue(
            'myapp',
            'stripe-events',
            'evt_async_1',
            '{"event":"test"}',
            '{"Stripe-Signature":"sig"}',
        );
        AsyncWebhookHandler::run($id);

        $this->assertNotNull($invoked_with, 'ability callback should fire');
        $this->assertIsArray($invoked_with);
        $this->assertSame(['event' => 'test'], $invoked_with['body']);
        $this->assertSame('{"event":"test"}', $invoked_with['raw']);
        $this->assertSame(['Stripe-Signature' => 'sig'], $invoked_with['headers']);
    }

    public function test_run_on_success_deletes_row_and_writes_ok_log(): void {
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', fn () => ['done' => true]);
        $id = AsyncWebhookHandler::enqueue('myapp', 'stripe-events', 'evt_ok', '{}', '{}');

        AsyncWebhookHandler::run($id);

        $this->assertNull(WebhookQueue::get($id), 'success must delete the queue row');
        $rows = WebhookLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(1, (int) $rows[0]['async']);
        $this->assertSame(200, (int) $rows[0]['http_status']);
    }

    public function test_run_wp_error_increments_attempts_and_reschedules(): void {
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', fn () => new \WP_Error('upstream', 'transient'));
        $id = AsyncWebhookHandler::enqueue('myapp', 'stripe-events', 'evt_retry', '{}', '{}');

        AsyncWebhookHandler::run($id);

        $row = WebhookQueue::get($id);
        $this->assertNotNull($row, 'row must persist for retry');
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('pending', $row['status']);
        $this->assertNotFalse(wp_next_scheduled(AsyncWebhookHandler::ASYNC_HOOK, [$id]));
        // No log row yet — we only log when the row terminally succeeds
        // or terminally fails.
        $this->assertCount(0, WebhookLog::query('myapp'));
    }

    public function test_run_after_max_attempts_marks_failed_and_writes_error_log(): void {
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', fn () => new \WP_Error('upstream', 'still broken'));
        $id = AsyncWebhookHandler::enqueue('myapp', 'stripe-events', 'evt_giveup', '{}', '{}');
        // Pre-set attempts to MAX_RETRIES - 1 so the next run() exhausts
        // the budget.
        WebhookQueue::increment_attempts($id);
        WebhookQueue::increment_attempts($id);  // attempts now 2

        AsyncWebhookHandler::run($id);

        $row = WebhookQueue::get($id);
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['attempts']);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('still broken', (string) $row['error_msg']);
        $this->assertFalse(
            wp_next_scheduled(AsyncWebhookHandler::ASYNC_HOOK, [$id]),
            'no further retry should be scheduled after the row is marked failed',
        );

        $rows = WebhookLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame(1, (int) $rows[0]['async']);
        $this->assertStringContainsString('still broken', (string) $rows[0]['error_msg']);
    }

    public function test_run_throwable_from_ability_treated_as_failure(): void {
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', function () {
            throw new \RuntimeException('boom');
        });
        $id = AsyncWebhookHandler::enqueue('myapp', 'stripe-events', 'evt_throw', '{}', '{}');

        AsyncWebhookHandler::run($id);

        $row = WebhookQueue::get($id);
        $this->assertNotNull($row, 'a throwing callback should leave the row for retry, not crash run()');
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('pending', $row['status']);
    }

    public function test_run_missing_row_is_silent_noop(): void {
        // No exception, no log row, no side effects.
        AsyncWebhookHandler::run(999999);
        $this->assertCount(0, WebhookLog::query('myapp'));
        $this->assertEmpty(_get_cron_array());
    }

    public function test_run_with_corrupted_body_marks_failed_without_retry(): void {
        $this->install_app_with_endpoint('myapp', 'stripe-events', 'myapp/handle', fn () => ['ok' => true]);
        $id = AsyncWebhookHandler::enqueue('myapp', 'stripe-events', 'evt_corrupt', '{}', '{}');
        // Corrupt the stored ciphertext after enqueue. Decryption must
        // fail, the row marked failed (not retried — retry won't help
        // when the ciphertext itself is unreadable), and a log row
        // written so ops sees the dead letter.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dsgo_apps_webhook_queue',
            ['encrypted_body' => str_repeat("\0", 64)],
            ['id' => $id],
        );

        AsyncWebhookHandler::run($id);

        $row = WebhookQueue::get($id);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('decrypt', strtolower((string) $row['error_msg']));
        $rows = WebhookLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
    }

    /**
     * Create a dsgo_app post with a manifest that declares one webhook
     * endpoint pointing at a published ability. Registers the ability
     * with $execute as its callback.
     */
    private function install_app_with_endpoint(string $app_id, string $endpoint_id, string $ability_name, callable $execute): void {
        $manifest_arr = [
            'manifest_version' => 1,
            'id'               => $app_id,
            'name'             => $app_id,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => [], 'run' => ['webhooks']],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => [[
                'name'        => $ability_name,
                'label'       => 'Handle',
                'description' => 'Handles the webhook event for the test.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ]]],
            'secrets'  => [['alias' => 'STRIPE', 'description' => 'Stripe signing secret (test).']],
            'webhooks' => ['endpoints' => [[
                'id'      => $endpoint_id,
                'ability' => $ability_name,
                'auth'    => ['type' => 'hmac-sha256', 'scheme' => 'stripe', 'secret_alias' => 'STRIPE'],
            ]]],
        ];
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $app_id,
            'post_title'  => $app_id,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest_arr);

        // Register the ability directly with WP so AsyncWebhookHandler's
        // wp_get_ability finds it. Bypasses AbilitiesPublisher because
        // these tests focus on the handler, not the publisher.
        global $wp_current_filter;
        if (function_exists('wp_register_ability_category') && function_exists('wp_has_ability_category')) {
            $wp_current_filter[] = 'wp_abilities_api_categories_init';
            try {
                if (!wp_has_ability_category('content')) {
                    wp_register_ability_category('content', ['label' => 'Content', 'description' => 'Test category.']);
                }
            } finally {
                array_pop($wp_current_filter);
            }
        }
        $wp_current_filter[] = 'wp_abilities_api_init';
        try {
            if (function_exists('wp_has_ability') && wp_has_ability($ability_name)) {
                wp_unregister_ability($ability_name);
            }
            wp_register_ability($ability_name, [
                'label'               => 'Handle webhook',
                'description'         => 'Test handler for async webhook delivery.',
                'category'            => 'content',
                'permission_callback' => '__return_true',
                'execute_callback'    => $execute,
                // WP_Ability::execute rejects non-null input unless the
                // ability declares an input_schema. Real webhook abilities
                // will declare one matching the spec contract; open object
                // here keeps the test focused on the dispatcher.
                'input_schema'        => [
                    'type'                 => 'object',
                    'additionalProperties' => true,
                ],
            ]);
        } finally {
            array_pop($wp_current_filter);
        }
    }
}
