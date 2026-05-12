<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\WebhookLog;
use WP_UnitTestCase;

/**
 * Tests for WebhookLog — bounded audit log for webhook deliveries.
 *
 * One row per webhook request (sync or async, success or failure).
 * Structure mirrors CronLog but with webhook-specific columns
 * (endpoint_id, http_status). The (app_id, endpoint_id) composite
 * index keeps "show this endpoint's recent deliveries" cheap;
 * (received_at) supports the retention prune.
 *
 * Retention default 14 days via the dsgo_apps_webhook_log_retention_days
 * filter — matches CronLog's posture (webhooks are bursty but not high-
 * volume in steady state).
 */
final class WebhookLogTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        WebhookLog::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_log");
    }

    public function test_create_table_is_idempotent(): void {
        WebhookLog::create_table();
        WebhookLog::create_table();
        global $wpdb;
        $name = $wpdb->prefix . 'dsgo_apps_webhook_log';
        // WP_UnitTestCase rewrites CREATE TABLE to CREATE TEMPORARY TABLE
        // for per-test isolation; SHOW TABLES doesn't list TEMPORARY tables.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `$name`");
        $this->assertNotEmpty($columns, "table $name must exist after create_table()");
    }

    public function test_insert_ok_row(): void {
        WebhookLog::insert([
            'app_id'      => 'myapp',
            'endpoint_id' => 'stripe-events',
            'received_at' => '2026-05-12 06:00:00',
            'duration_ms' => 142,
            'http_status' => 200,
            'status'      => 'ok',
            'async'       => false,
            'error_code'  => null,
            'error_msg'   => null,
        ]);
        $rows = WebhookLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('stripe-events', $rows[0]['endpoint_id']);
        $this->assertSame('ok',  $rows[0]['status']);
        $this->assertSame(200,  (int) $rows[0]['http_status']);
        $this->assertSame(0,    (int) $rows[0]['async']);
        $this->assertNull($rows[0]['error_code']);
    }

    public function test_insert_error_row(): void {
        WebhookLog::insert([
            'app_id'      => 'myapp',
            'endpoint_id' => 'stripe-events',
            'received_at' => '2026-05-12 06:00:00',
            'duration_ms' => 5,
            'http_status' => 401,
            'status'      => 'error',
            'async'       => false,
            'error_code'  => 'webhook_auth_failed',
            'error_msg'   => 'Webhook authentication failed.',
        ]);
        $rows = WebhookLog::query('myapp');
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame(401, (int) $rows[0]['http_status']);
        $this->assertSame('webhook_auth_failed', $rows[0]['error_code']);
    }

    public function test_insert_async_row(): void {
        WebhookLog::insert($this->row('myapp', 'stripe-events', 'ok', async: true));
        $rows = WebhookLog::query('myapp');
        $this->assertSame(1, (int) $rows[0]['async']);
    }

    public function test_query_scopes_by_app_id(): void {
        WebhookLog::insert($this->row('alpha', 'ep-a', 'ok'));
        WebhookLog::insert($this->row('beta',  'ep-b', 'ok'));
        $this->assertCount(1, WebhookLog::query('alpha'));
        $this->assertCount(1, WebhookLog::query('beta'));
    }

    public function test_query_filter_by_endpoint_id(): void {
        WebhookLog::insert($this->row('myapp', 'stripe', 'ok'));
        WebhookLog::insert($this->row('myapp', 'github', 'ok'));
        $rows = WebhookLog::query('myapp', ['endpoint_id' => 'github']);
        $this->assertCount(1, $rows);
        $this->assertSame('github', $rows[0]['endpoint_id']);
    }

    public function test_query_orders_by_received_at_desc(): void {
        WebhookLog::insert($this->row_with_time('myapp', 'ep', strtotime('2026-05-01 00:00:00')));
        WebhookLog::insert($this->row_with_time('myapp', 'ep', strtotime('2026-05-10 00:00:00')));
        WebhookLog::insert($this->row_with_time('myapp', 'ep', strtotime('2026-05-05 00:00:00')));
        $rows = WebhookLog::query('myapp');
        $this->assertSame('2026-05-10 00:00:00', $rows[0]['received_at']);
        $this->assertSame('2026-05-05 00:00:00', $rows[1]['received_at']);
        $this->assertSame('2026-05-01 00:00:00', $rows[2]['received_at']);
    }

    public function test_query_pagination(): void {
        for ($i = 1; $i <= 5; $i++) {
            WebhookLog::insert($this->row('myapp', "ep-$i", 'ok'));
        }
        $page = WebhookLog::query('myapp', ['per_page' => 2, 'offset' => 1]);
        $this->assertCount(2, $page);
    }

    public function test_prune_drops_old_rows_only(): void {
        $now = time();
        WebhookLog::insert($this->row_with_time('myapp', 'old', $now - 30 * DAY_IN_SECONDS));
        WebhookLog::insert($this->row_with_time('myapp', 'mid', $now - 5  * DAY_IN_SECONDS));
        WebhookLog::insert($this->row_with_time('myapp', 'new', $now - DAY_IN_SECONDS));
        $deleted = WebhookLog::prune(10);
        $this->assertSame(1, $deleted);
        $remaining = array_column(WebhookLog::query('myapp'), 'endpoint_id');
        $this->assertContains('mid', $remaining);
        $this->assertContains('new', $remaining);
        $this->assertNotContains('old', $remaining);
    }

    public function test_retention_days_default_is_14(): void {
        $this->assertSame(14, WebhookLog::retention_days());
    }

    public function test_retention_days_is_filterable(): void {
        add_filter('dsgo_apps_webhook_log_retention_days', static fn () => 30);
        $this->assertSame(30, WebhookLog::retention_days());
        remove_all_filters('dsgo_apps_webhook_log_retention_days');
    }

    /** @return array<string, mixed> */
    private function row(string $app_id, string $endpoint_id, string $status, bool $async = false): array {
        return [
            'app_id'      => $app_id,
            'endpoint_id' => $endpoint_id,
            'received_at' => current_time('mysql', true),
            'duration_ms' => 50,
            'http_status' => $status === 'ok' ? 200 : 500,
            'status'      => $status,
            'async'       => $async,
            'error_code'  => null,
            'error_msg'   => null,
        ];
    }

    /** @return array<string, mixed> */
    private function row_with_time(string $app_id, string $endpoint_id, int $epoch): array {
        return [
            'app_id'      => $app_id,
            'endpoint_id' => $endpoint_id,
            'received_at' => gmdate('Y-m-d H:i:s', $epoch),
            'duration_ms' => 0,
            'http_status' => 200,
            'status'      => 'ok',
            'async'       => false,
            'error_code'  => null,
            'error_msg'   => null,
        ];
    }
}
