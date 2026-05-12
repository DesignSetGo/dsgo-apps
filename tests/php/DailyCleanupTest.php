<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CronLog;
use DSGo_Apps\WebhookLog;
use WP_UnitTestCase;

/**
 * Tests for Task 17 of the cron+webhooks plan: the `dsgo_apps_daily_cleanup`
 * retention sweep.
 *
 * One scheduled daily WP-cron event hangs the prune calls for both
 * CronLog and WebhookLog. The hook respects the
 * dsgo_apps_cron_log_retention_days and dsgo_apps_webhook_log_retention_days
 * filters (each defaulting to 14 days).
 */
final class DailyCleanupTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CronLog::create_table();
        WebhookLog::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_cron_log");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_log");
        // Make sure the production class binds its action listener.
        // Plugin::get_instance() is idempotent.
        \DSGo_Apps\Plugin::get_instance();
    }

    public function test_daily_cleanup_action_is_registered(): void {
        $this->assertNotFalse(
            has_action('dsgo_apps_daily_cleanup'),
            'Plugin must bind a listener to dsgo_apps_daily_cleanup so retention runs at all',
        );
    }

    public function test_daily_cleanup_prunes_old_cron_log_rows(): void {
        $now = time();
        // 30 days old — outside default 14-day window.
        CronLog::insert([
            'app_id'       => 'myapp',
            'job_id'       => 'old',
            'ability_name' => 'myapp/x',
            'fired_at'     => gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS),
            'duration_ms'  => 0,
            'status'       => 'ok',
            'error_code'   => null,
            'error_msg'    => null,
        ]);
        // 1 day old — inside window.
        CronLog::insert([
            'app_id'       => 'myapp',
            'job_id'       => 'recent',
            'ability_name' => 'myapp/x',
            'fired_at'     => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS),
            'duration_ms'  => 0,
            'status'       => 'ok',
            'error_code'   => null,
            'error_msg'    => null,
        ]);

        do_action('dsgo_apps_daily_cleanup');

        $remaining = array_column(CronLog::query('myapp'), 'job_id');
        $this->assertContains('recent', $remaining);
        $this->assertNotContains('old', $remaining);
    }

    public function test_daily_cleanup_prunes_old_webhook_log_rows(): void {
        $now = time();
        WebhookLog::insert([
            'app_id'      => 'myapp',
            'endpoint_id' => 'old-endpoint',
            'received_at' => gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS),
            'duration_ms' => 0,
            'http_status' => 200,
            'status'      => 'ok',
            'async'       => false,
            'error_code'  => null,
            'error_msg'   => null,
        ]);
        WebhookLog::insert([
            'app_id'      => 'myapp',
            'endpoint_id' => 'recent-endpoint',
            'received_at' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS),
            'duration_ms' => 0,
            'http_status' => 200,
            'status'      => 'ok',
            'async'       => false,
            'error_code'  => null,
            'error_msg'   => null,
        ]);

        do_action('dsgo_apps_daily_cleanup');

        $remaining = array_column(WebhookLog::query('myapp'), 'endpoint_id');
        $this->assertContains('recent-endpoint', $remaining);
        $this->assertNotContains('old-endpoint', $remaining);
    }

    public function test_daily_cleanup_honors_retention_filter(): void {
        $now = time();
        CronLog::insert([
            'app_id'       => 'myapp',
            'job_id'       => 'middle-age',
            'ability_name' => 'myapp/x',
            // 5 days old — inside default 14-day window, OUTSIDE a
            // 1-day window if the filter is honored.
            'fired_at'     => gmdate('Y-m-d H:i:s', $now - 5 * DAY_IN_SECONDS),
            'duration_ms'  => 0,
            'status'       => 'ok',
            'error_code'   => null,
            'error_msg'    => null,
        ]);

        add_filter('dsgo_apps_cron_log_retention_days', static fn () => 1);
        do_action('dsgo_apps_daily_cleanup');
        remove_all_filters('dsgo_apps_cron_log_retention_days');

        $this->assertCount(0, CronLog::query('myapp'),
            'filter override must shrink the retention window');
    }
}
