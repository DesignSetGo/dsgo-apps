<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CronLog;
use WP_UnitTestCase;

/**
 * Tests for CronLog — bounded audit log for cron-driven ability invocations.
 *
 * One row per CronDispatcher::run() call (success OR failure). Stored in a
 * dedicated table so per-app cron history queries don't have to scan options
 * or postmeta. The (app_id, job_id) composite index keeps "show this job's
 * last N fires" cheap.
 *
 * Retention is filterable via `dsgo_apps_cron_log_retention_days`
 * (default 14, wider than http log because cron is lower-volume).
 */
final class CronLogTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CronLog::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_cron_log");
    }

    public function test_create_table_is_idempotent(): void {
        CronLog::create_table();
        CronLog::create_table();
        global $wpdb;
        $name = $wpdb->prefix . 'dsgo_apps_cron_log';
        // WP_UnitTestCase rewrites CREATE TABLE to CREATE TEMPORARY TABLE for
        // per-test isolation; MySQL's SHOW TABLES does not list TEMPORARY
        // tables, so the existence check has to use DESCRIBE (which does).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `$name`");
        $this->assertNotEmpty($columns, "table $name must exist after create_table()");
    }

    public function test_insert_ok_row(): void {
        CronLog::insert([
            'app_id'       => 'myapp',
            'job_id'       => 'daily-digest',
            'ability_name' => 'myapp/build-digest',
            'fired_at'     => '2026-05-11 06:00:00',
            'duration_ms'  => 1234,
            'status'       => 'ok',
            'error_code'   => null,
            'error_msg'    => null,
        ]);
        $rows = CronLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('daily-digest', $rows[0]['job_id']);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(1234, (int) $rows[0]['duration_ms']);
        $this->assertNull($rows[0]['error_code']);
    }

    public function test_insert_error_row(): void {
        CronLog::insert([
            'app_id'       => 'myapp',
            'job_id'       => 'nightly-cleanup',
            'ability_name' => 'myapp/cleanup',
            'fired_at'     => '2026-05-11 02:00:00',
            'duration_ms'  => 25,
            'status'       => 'error',
            'error_code'   => 'cron_ability_not_found',
            'error_msg'    => 'Ability not registered',
        ]);
        $rows = CronLog::query('myapp');
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('cron_ability_not_found', $rows[0]['error_code']);
        $this->assertSame('Ability not registered', $rows[0]['error_msg']);
    }

    public function test_query_scopes_by_app_id(): void {
        CronLog::insert($this->row('alpha', 'job-a', 'ok'));
        CronLog::insert($this->row('beta',  'job-b', 'ok'));
        $alpha_rows = CronLog::query('alpha');
        $beta_rows  = CronLog::query('beta');
        $this->assertCount(1, $alpha_rows);
        $this->assertCount(1, $beta_rows);
        $this->assertSame('job-a', $alpha_rows[0]['job_id']);
        $this->assertSame('job-b', $beta_rows[0]['job_id']);
    }

    public function test_query_filter_by_job_id(): void {
        CronLog::insert($this->row('myapp', 'job-a', 'ok'));
        CronLog::insert($this->row('myapp', 'job-b', 'ok'));
        $rows = CronLog::query('myapp', ['job_id' => 'job-b']);
        $this->assertCount(1, $rows);
        $this->assertSame('job-b', $rows[0]['job_id']);
    }

    public function test_query_orders_by_fired_at_desc(): void {
        CronLog::insert(['app_id' => 'myapp', 'job_id' => 'j', 'ability_name' => 'myapp/x', 'fired_at' => '2026-05-01 06:00:00', 'duration_ms' => 0, 'status' => 'ok', 'error_code' => null, 'error_msg' => null]);
        CronLog::insert(['app_id' => 'myapp', 'job_id' => 'j', 'ability_name' => 'myapp/x', 'fired_at' => '2026-05-10 06:00:00', 'duration_ms' => 0, 'status' => 'ok', 'error_code' => null, 'error_msg' => null]);
        CronLog::insert(['app_id' => 'myapp', 'job_id' => 'j', 'ability_name' => 'myapp/x', 'fired_at' => '2026-05-05 06:00:00', 'duration_ms' => 0, 'status' => 'ok', 'error_code' => null, 'error_msg' => null]);
        $rows = CronLog::query('myapp');
        $this->assertSame('2026-05-10 06:00:00', $rows[0]['fired_at']);
        $this->assertSame('2026-05-05 06:00:00', $rows[1]['fired_at']);
        $this->assertSame('2026-05-01 06:00:00', $rows[2]['fired_at']);
    }

    public function test_query_pagination(): void {
        for ($i = 1; $i <= 5; $i++) {
            CronLog::insert($this->row('myapp', "job-$i", 'ok'));
        }
        $page = CronLog::query('myapp', ['per_page' => 2, 'offset' => 1]);
        $this->assertCount(2, $page);
    }

    public function test_prune_drops_old_rows_only(): void {
        // Three rows: 30 days, 5 days, 1 day old.
        $now = time();
        CronLog::insert($this->row_with_time('myapp', 'old', $now - 30 * DAY_IN_SECONDS));
        CronLog::insert($this->row_with_time('myapp', 'mid', $now - 5  * DAY_IN_SECONDS));
        CronLog::insert($this->row_with_time('myapp', 'new', $now - DAY_IN_SECONDS));
        $deleted = CronLog::prune(10);
        $this->assertSame(1, $deleted);
        $rows = CronLog::query('myapp');
        $this->assertCount(2, $rows);
        $remaining_jobs = array_column($rows, 'job_id');
        $this->assertContains('mid', $remaining_jobs);
        $this->assertContains('new', $remaining_jobs);
        $this->assertNotContains('old', $remaining_jobs);
    }

    /** @return array<string, mixed> */
    private function row(string $app_id, string $job_id, string $status): array {
        return [
            'app_id'       => $app_id,
            'job_id'       => $job_id,
            'ability_name' => "$app_id/x",
            'fired_at'     => current_time('mysql', true),
            'duration_ms'  => 100,
            'status'       => $status,
            'error_code'   => null,
            'error_msg'    => null,
        ];
    }

    /** @return array<string, mixed> */
    private function row_with_time(string $app_id, string $job_id, int $epoch): array {
        return [
            'app_id'       => $app_id,
            'job_id'       => $job_id,
            'ability_name' => "$app_id/x",
            'fired_at'     => gmdate('Y-m-d H:i:s', $epoch),
            'duration_ms'  => 0,
            'status'       => 'ok',
            'error_code'   => null,
            'error_msg'    => null,
        ];
    }
}
