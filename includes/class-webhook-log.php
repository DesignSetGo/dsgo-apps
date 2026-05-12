<?php
/**
 * Bounded audit log for webhook deliveries.
 *
 * One row per accepted webhook request — sync OR async, success OR
 * failure. Sister table to CronLog: same shape, same retention
 * posture, but with webhook-specific columns (endpoint_id, the HTTP
 * status returned to the sender, an `async` flag separating sync
 * from queued deliveries).
 *
 * Indexes:
 *   - (app_id, endpoint_id) — "show this endpoint's recent deliveries"
 *   - (received_at)         — supports the retention prune
 *
 * Retention: filterable via `dsgo_apps_webhook_log_retention_days`,
 * default 14 days. Daily cleanup hook lands in Task 17 of the plan.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookLog {

    private const TABLE_SLUG = 'dsgo_apps_webhook_log';
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
        // status + http_status are plain VARCHAR/SMALLINT rather than ENUM
        // so dbDelta's reformatting on upgrade doesn't silently rewrite
        // values that drift from the enum spec (matches CronLog's posture).
        $sql = "CREATE TABLE $table (
            id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
            app_id       VARCHAR(64)       NOT NULL,
            endpoint_id  VARCHAR(64)       NOT NULL,
            received_at  DATETIME          NOT NULL,
            duration_ms  INT UNSIGNED      NOT NULL,
            http_status  SMALLINT UNSIGNED NOT NULL,
            status       VARCHAR(10)       NOT NULL,
            async        TINYINT(1)        NOT NULL DEFAULT 0,
            error_code   VARCHAR(64)       NULL,
            error_msg    TEXT              NULL,
            PRIMARY KEY    (id),
            KEY app_ep     (app_id, endpoint_id),
            KEY received_at (received_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Record one webhook delivery attempt. Caller assembles the row.
     *
     * @param array{
     *   app_id:string,
     *   endpoint_id:string,
     *   received_at:string,
     *   duration_ms:int,
     *   http_status:int,
     *   status:string,
     *   async:bool,
     *   error_code:?string,
     *   error_msg:?string,
     * } $row
     */
    public static function insert(array $row): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom log table; $wpdb->insert is the correct WP API for write-path log inserts; no caching layer applies to append-only writes
        $ok = $wpdb->insert(
            self::table_name(),
            [
                'app_id'      => $row['app_id'],
                'endpoint_id' => $row['endpoint_id'],
                'received_at' => $row['received_at'],
                'duration_ms' => max(0, (int) $row['duration_ms']),
                'http_status' => max(0, (int) $row['http_status']),
                'status'      => $row['status'],
                'async'       => !empty($row['async']) ? 1 : 0,
                'error_code'  => $row['error_code'],
                'error_msg'   => $row['error_msg'],
            ],
            ['%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s'],
        );
        if ($ok === false) {
            // Audit gaps are silent by nature — log a breadcrumb but
            // don't throw. Webhook delivery must never be masked by a
            // log-write failure.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG gated; intentional for production debugging of webhook log write failures
                error_log(sprintf(
                    'dsgo_apps: WebhookLog::insert failed (app=%s endpoint=%s): %s',
                    $row['app_id'] ?? '?',
                    $row['endpoint_id'] ?? '?',
                    $wpdb->last_error,
                ));
            }
        }
    }

    /**
     * Read recent rows for an app, newest first.
     *
     * @param array{endpoint_id?:string, per_page?:int, offset?:int} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $app_id, array $filters = []): array {
        global $wpdb;
        $table  = self::table_name();
        $limit  = max(1, (int) ($filters['per_page'] ?? 50));
        $offset = max(0, (int) ($filters['offset']   ?? 0));

        if (!empty($filters['endpoint_id']) && is_string($filters['endpoint_id'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table; $table built from $wpdb->prefix (not user input); log read: cache invalidation cost outweighs caching benefit for bounded per-endpoint history queries
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE app_id = %s AND endpoint_id = %s ORDER BY received_at DESC LIMIT %d OFFSET %d',
                $table,
                $app_id,
                $filters['endpoint_id'],
                $limit,
                $offset,
            ), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table; $table built from $wpdb->prefix (not user input); log read: cache invalidation cost outweighs caching benefit for bounded per-app history queries
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE app_id = %s ORDER BY received_at DESC LIMIT %d OFFSET %d',
                $table,
                $app_id,
                $limit,
                $offset,
            ), ARRAY_A);
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Delete rows older than $days days. Returns the number deleted.
     * Bounded by PRUNE_BATCH_LIMIT so a long backlog doesn't lock the
     * table on the cleanup cron tick.
     */
    public static function prune(int $days): int {
        global $wpdb;
        if ($days < 1) $days = 1;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $table  = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table; $table built from $wpdb->prefix (not user input); log write: no caching applies to batch DELETE retention purges
        $count = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE received_at < %s LIMIT %d',
            $table,
            $cutoff,
            self::PRUNE_BATCH_LIMIT,
        ));
        return (int) $count;
    }

    /**
     * Filterable retention window in days. Single authoritative source
     * for the filter name.
     */
    public static function retention_days(): int {
        $days = (int) apply_filters('dsgo_apps_webhook_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        return $days < 1 ? 1 : $days;
    }
}
