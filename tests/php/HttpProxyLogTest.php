<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Http_Proxy_Log;
use WP_UnitTestCase;

/**
 * Tests for Http_Proxy_Log — bounded audit log for the HTTP proxy.
 *
 * The table is created via dbDelta on activation. Each fetch through
 * Http_Proxy_Bridge writes one row. A daily cron purges rows older than
 * the retention window (default 7 days, filterable). Failed fetches log
 * status=0 so the row count tracks attempts, not just successes.
 */
final class HttpProxyLogTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Ensure the table exists for every test — dbDelta is idempotent.
        Http_Proxy_Log::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_http_log");
    }

    public function test_create_table_is_idempotent(): void {
        // Re-running create_table on an existing table must not throw.
        Http_Proxy_Log::create_table();
        Http_Proxy_Log::create_table();
        global $wpdb;
        $name = $wpdb->prefix . 'dsgo_apps_http_log';
        // WP_UnitTestCase rewrites CREATE TABLE to CREATE TEMPORARY TABLE for
        // per-test isolation; MySQL's SHOW TABLES does not list TEMPORARY
        // tables, so the existence check has to use DESCRIBE (which does).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `$name`");
        $this->assertNotEmpty($columns, "table $name must exist after create_table()");
    }

    public function test_log_inserts_row_with_all_fields(): void {
        Http_Proxy_Log::log(
            app_id: 'stripe-checkout',
            host: 'api.stripe.com',
            method: 'POST',
            path: '/v1/charges',
            status: 200,
            duration_ms: 142,
            req_bytes: 87,
            resp_bytes: 1240,
        );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1", ARRAY_A);
        $this->assertNotNull($row);
        $this->assertSame('stripe-checkout', $row['app_id']);
        $this->assertSame('api.stripe.com', $row['host']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('/v1/charges', $row['path']);
        $this->assertSame(200, (int) $row['status']);
        $this->assertSame(142, (int) $row['duration_ms']);
        $this->assertSame(87,  (int) $row['req_bytes']);
        $this->assertSame(1240, (int) $row['resp_bytes']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function test_log_records_status_zero_for_transport_failure(): void {
        // Transport-level failure (DNS, timeout, connection refused) logs
        // status=0 so retention/counting includes failed attempts.
        Http_Proxy_Log::log(
            app_id: 'app-a',
            host: 'api.example.com',
            method: 'GET',
            path: '/',
            status: 0,
            duration_ms: 5000,
            req_bytes: 0,
            resp_bytes: 0,
        );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $status = $wpdb->get_var("SELECT status FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1");
        $this->assertSame(0, (int) $status);
    }

    public function test_log_truncates_oversized_path(): void {
        // The path column is VARCHAR(2000). Paths longer than that get
        // truncated rather than throwing, because the audit log is best-
        // effort observability — a giant path should not break the
        // user-facing request that triggered it.
        $long = '/' . str_repeat('a', 2500);
        Http_Proxy_Log::log(
            app_id: 'app-a',
            host: 'api.example.com',
            method: 'GET',
            path: $long,
            status: 200,
            duration_ms: 10,
            req_bytes: 0,
            resp_bytes: 0,
        );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stored = (string) $wpdb->get_var("SELECT path FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1");
        $this->assertSame(2000, strlen($stored));
    }

    public function test_purge_expired_deletes_rows_older_than_retention(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dsgo_apps_http_log';

        // Insert two rows: one 10 days old, one 1 day old. Default
        // retention is 7 days; only the older row should be purged.
        $old_ts = gmdate('Y-m-d H:i:s', time() - (10 * DAY_IN_SECONDS));
        $new_ts = gmdate('Y-m-d H:i:s', time() - (1 * DAY_IN_SECONDS));
        $wpdb->insert($table, [
            'app_id' => 'a', 'host' => 'h', 'method' => 'GET', 'path' => '/',
            'status' => 200, 'duration_ms' => 1, 'req_bytes' => 0, 'resp_bytes' => 0,
            'created_at' => $old_ts,
        ]);
        $wpdb->insert($table, [
            'app_id' => 'a', 'host' => 'h', 'method' => 'GET', 'path' => '/',
            'status' => 200, 'duration_ms' => 1, 'req_bytes' => 0, 'resp_bytes' => 0,
            'created_at' => $new_ts,
        ]);

        Http_Proxy_Log::purge_expired();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $this->assertSame(1, $count, 'older row should be purged, newer row should remain');
    }

    public function test_purge_expired_respects_filter(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dsgo_apps_http_log';

        // Row from 3 days ago: under default 7-day retention (kept), but
        // a 1-day filter override should purge it.
        $ts = gmdate('Y-m-d H:i:s', time() - (3 * DAY_IN_SECONDS));
        $wpdb->insert($table, [
            'app_id' => 'a', 'host' => 'h', 'method' => 'GET', 'path' => '/',
            'status' => 200, 'duration_ms' => 1, 'req_bytes' => 0, 'resp_bytes' => 0,
            'created_at' => $ts,
        ]);

        add_filter('dsgo_apps_http_log_retention_days', static fn (): int => 1);
        try {
            Http_Proxy_Log::purge_expired();
        } finally {
            remove_all_filters('dsgo_apps_http_log_retention_days');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $this->assertSame(0, $count);
    }

    public function test_table_name_returns_prefixed_name(): void {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'dsgo_apps_http_log', Http_Proxy_Log::table_name());
    }

    public function test_activate_creates_table_and_schedules_cron(): void {
        // End-to-end activation contract: Plugin::activate() must leave the
        // table present and the daily purge cron registered. Earlier review
        // caught a regression where the wiring was missing — this test
        // would have failed loudly.
        global $wpdb;
        $table = $wpdb->prefix . 'dsgo_apps_http_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $wpdb->query("DROP TABLE IF EXISTS $table");
        wp_unschedule_hook(Http_Proxy_Log::CRON_HOOK);

        \DSGo_Apps\Plugin::activate();

        // SHOW TABLES omits TEMPORARY tables, which the WP test framework
        // rewrites all CREATE TABLE statements into; use DESCRIBE instead.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `$table`");
        $this->assertNotEmpty($columns, 'activate() must create the http_log table');

        $next = wp_next_scheduled(Http_Proxy_Log::CRON_HOOK);
        $this->assertIsInt($next, 'activate() must schedule the daily purge cron');
        $this->assertGreaterThan(time(), $next);
    }

    public function test_deactivate_clears_scheduled_cron(): void {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Http_Proxy_Log::CRON_HOOK);
        $this->assertNotFalse(wp_next_scheduled(Http_Proxy_Log::CRON_HOOK));

        \DSGo_Apps\Plugin::deactivate();

        $this->assertFalse(wp_next_scheduled(Http_Proxy_Log::CRON_HOOK));
    }

    public function test_idx_app_created_index_exists(): void {
        // The (app_id, created_at) composite index is what makes the
        // admin "show this app's recent traffic" query cheap on busy
        // sites; assert it landed via dbDelta.
        global $wpdb;
        $table = $wpdb->prefix . 'dsgo_apps_http_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        $names = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('idx_app_created', $names);
    }
}
