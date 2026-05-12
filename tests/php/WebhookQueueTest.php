<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\WebhookQueue;
use WP_UnitTestCase;

/**
 * Tests for WebhookQueue — async webhook delivery queue table.
 *
 * The queue stores webhook requests that the manifest opted into
 * async handling (`webhooks.endpoints[].async: true`). One row per
 * accepted request; AsyncWebhookHandler::run() pulls a row by id,
 * decrypts the body + headers, invokes the ability, then either
 * deletes the row (success), reschedules via wp_schedule_single_event
 * (transient failure, ≤ 3 attempts), or marks status=failed.
 *
 * Bodies and headers are encrypted at rest with the per-app vault
 * key — so even if the queue table leaks, the payload bytes are
 * recoverable only by the site that received them. WebhookQueue is
 * the storage layer; encryption happens upstream in
 * AsyncWebhookHandler::enqueue (Task 11). This test class verifies
 * the storage contract only — opaque blobs in, opaque blobs out.
 */
final class WebhookQueueTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        WebhookQueue::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_queue");
    }

    public function test_create_table_is_idempotent(): void {
        WebhookQueue::create_table();
        WebhookQueue::create_table();
        global $wpdb;
        $name = $wpdb->prefix . 'dsgo_apps_webhook_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `$name`");
        $this->assertNotEmpty($columns, "table $name must exist after create_table()");
    }

    public function test_insert_returns_row_id(): void {
        $id = WebhookQueue::insert($this->row());
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function test_get_returns_inserted_row(): void {
        $id  = WebhookQueue::insert($this->row(
            app_id: 'myapp',
            endpoint_id: 'stripe-events',
            idempotency_key: 'evt_123',
            encrypted_body: 'OPAQUE_BODY_BLOB',
            encrypted_headers: 'OPAQUE_HEADERS_BLOB',
        ));
        $row = WebhookQueue::get($id);
        $this->assertNotNull($row);
        $this->assertSame('myapp',               $row['app_id']);
        $this->assertSame('stripe-events',       $row['endpoint_id']);
        $this->assertSame('evt_123',             $row['idempotency_key']);
        $this->assertSame('OPAQUE_BODY_BLOB',    $row['encrypted_body']);
        $this->assertSame('OPAQUE_HEADERS_BLOB', $row['encrypted_headers']);
        $this->assertSame(0,                     (int) $row['attempts']);
        $this->assertSame('pending',             $row['status']);
        $this->assertNull($row['error_msg']);
    }

    public function test_get_returns_null_for_missing_row(): void {
        $this->assertNull(WebhookQueue::get(999999));
    }

    public function test_increment_attempts_returns_new_count(): void {
        $id = WebhookQueue::insert($this->row());
        $this->assertSame(1, WebhookQueue::increment_attempts($id));
        $this->assertSame(2, WebhookQueue::increment_attempts($id));
        $this->assertSame(2, (int) WebhookQueue::get($id)['attempts']);
    }

    public function test_mark_failed_sets_status_and_error_msg(): void {
        $id = WebhookQueue::insert($this->row());
        WebhookQueue::mark_failed($id, 'Max retries exceeded');
        $row = WebhookQueue::get($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Max retries exceeded', $row['error_msg']);
    }

    public function test_mark_failed_truncates_long_error_msg(): void {
        // TEXT column can hold a lot, but defensive truncation prevents
        // a runaway message from blowing out per-row storage.
        $id = WebhookQueue::insert($this->row());
        $long = str_repeat('x', 8192);
        WebhookQueue::mark_failed($id, $long);
        $row = WebhookQueue::get($id);
        $this->assertLessThanOrEqual(2000, strlen($row['error_msg']));
    }

    public function test_delete_removes_row(): void {
        $id = WebhookQueue::insert($this->row());
        $this->assertNotNull(WebhookQueue::get($id));
        WebhookQueue::delete($id);
        $this->assertNull(WebhookQueue::get($id));
    }

    public function test_null_idempotency_key_round_trip(): void {
        // Endpoints without idempotency_header send null here.
        $id  = WebhookQueue::insert($this->row(idempotency_key: null));
        $row = WebhookQueue::get($id);
        $this->assertNull($row['idempotency_key']);
    }

    /** @return array<string, mixed> */
    private function row(
        string $app_id            = 'myapp',
        string $endpoint_id       = 'stripe-events',
        ?string $idempotency_key  = 'evt_default',
        string $encrypted_body    = 'BODY_BLOB',
        string $encrypted_headers = 'HEADERS_BLOB',
    ): array {
        return [
            'app_id'            => $app_id,
            'endpoint_id'       => $endpoint_id,
            'idempotency_key'   => $idempotency_key,
            'encrypted_body'    => $encrypted_body,
            'encrypted_headers' => $encrypted_headers,
            'received_at'       => current_time('mysql', true),
        ];
    }
}
