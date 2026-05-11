<?php
/**
 * Bounded audit log for cron-driven ability invocations.
 *
 * One row per `CronDispatcher::run()` call — success OR failure. Stored in
 * a dedicated table so per-app cron-history queries don't have to scan
 * wp_options or wp_postmeta. The (app_id, job_id) composite index keeps
 * "show this job's last N fires" cheap; (fired_at) helps the retention purge.
 *
 * Retention is filterable (`dsgo_apps_cron_log_retention_days`, default 14).
 * The daily cleanup hook (Task 18) calls CronLog::prune() with that value.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class CronLog {

    private const TABLE_SLUG = 'dsgo_apps_cron_log';
    private const DEFAULT_RETENTION_DAYS = 14;
    /** Delete in chunks so a long backlog doesn't lock the table on cron. */
    private const PRUNE_BATCH_LIMIT = 5000;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Idempotent — safe to call on every activation and every upgrade.
     * dbDelta no-ops if the schema already matches.
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id       VARCHAR(64)  NOT NULL,
            job_id       VARCHAR(64)  NOT NULL,
            ability_name VARCHAR(255) NOT NULL,
            fired_at     DATETIME     NOT NULL,
            duration_ms  INT UNSIGNED NOT NULL,
            status       VARCHAR(10)  NOT NULL,
            error_code   VARCHAR(64)  NULL,
            error_msg    TEXT         NULL,
            PRIMARY KEY  (id),
            KEY app_job  (app_id, job_id),
            KEY fired_at (fired_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Record one cron-dispatch attempt. Caller assembles the row; we just
     * insert. `status` is one of `ok` | `error`; the column is a plain
     * VARCHAR rather than ENUM so dbDelta's reformatting on upgrade doesn't
     * silently rewrite values that don't match the enum spec.
     *
     * @param array{
     *   app_id:string,
     *   job_id:string,
     *   ability_name:string,
     *   fired_at:string,
     *   duration_ms:int,
     *   status:string,
     *   error_code:?string,
     *   error_msg:?string,
     * } $row
     */
    public static function insert(array $row): void {
        global $wpdb;
        $ok = $wpdb->insert(
            self::table_name(),
            [
                'app_id'       => $row['app_id'],
                'job_id'       => $row['job_id'],
                'ability_name' => $row['ability_name'],
                'fired_at'     => $row['fired_at'],
                'duration_ms'  => max(0, (int) $row['duration_ms']),
                'status'       => $row['status'],
                'error_code'   => $row['error_code'],
                'error_msg'    => $row['error_msg'],
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
        );
        if ($ok === false) {
            // Audit gaps are silent by nature — if we can't write the
            // row, leave a breadcrumb in the PHP error log so missing
            // history isn't invisible to operators. We deliberately do
            // NOT throw: the caller is mid-cron-tick and we never want
            // log-write failures to mask the underlying job outcome.
            error_log(sprintf(
                'dsgo_apps: CronLog::insert failed (app=%s job=%s): %s',
                $row['app_id'] ?? '?',
                $row['job_id'] ?? '?',
                $wpdb->last_error,
            ));
        }
    }

    /**
     * Read recent rows for an app, newest first. Optional `job_id` filter,
     * `per_page` (default 50), `offset` (default 0).
     *
     * @param array{job_id?:string, per_page?:int, offset?:int} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $app_id, array $filters = []): array {
        global $wpdb;
        $table  = self::table_name();
        $limit  = max(1, (int) ($filters['per_page'] ?? 50));
        $offset = max(0, (int) ($filters['offset']   ?? 0));

        if (!empty($filters['job_id']) && is_string($filters['job_id'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE app_id = %s AND job_id = %s ORDER BY fired_at DESC LIMIT %d OFFSET %d",
                $app_id,
                $filters['job_id'],
                $limit,
                $offset,
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE app_id = %s ORDER BY fired_at DESC LIMIT %d OFFSET %d",
                $app_id,
                $limit,
                $offset,
            ), ARRAY_A);
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Drop rows older than `$days` days. Returns the number deleted. Bounded
     * by PRUNE_BATCH_LIMIT so a long backlog doesn't lock the table — the
     * caller is expected to be a recurring cron, so partial deletes resolve
     * across multiple ticks.
     */
    public static function prune(int $days): int {
        global $wpdb;
        if ($days < 1) $days = 1;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $table  = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $count = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE fired_at < %s LIMIT %d",
            $cutoff,
            self::PRUNE_BATCH_LIMIT,
        ));
        return (int) $count;
    }

    /**
     * Filterable retention window in days. Used by the daily cleanup hook
     * once Task 18 wires it. Lives here so the filter name has a single
     * authoritative source.
     */
    public static function retention_days(): int {
        $days = (int) apply_filters('dsgo_apps_cron_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        return $days < 1 ? 1 : $days;
    }
}
