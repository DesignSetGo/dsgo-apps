<?php
/**
 * Bounded audit log for the HTTP proxy.
 *
 * One row per `dsgo.http.fetch()` attempt — success OR transport failure
 * (transport failure logs status=0). Stored in a dedicated table so per-app
 * counts and recent-traffic queries don't have to scan wp_options or
 * wp_postmeta. The (app_id, created_at) composite index keeps "show this
 * app's last N requests" cheap.
 *
 * Retention is filterable (`dsgo_apps_http_log_retention_days`, default 7).
 * Plugin::activate() schedules `dsgo_apps_http_log_purge` daily; that hook
 * calls Http_Proxy_Log::purge_expired(). Deactivate clears the schedule.
 *
 * Best-effort by design: a path longer than VARCHAR(2000) is truncated
 * rather than rejected — the audit log should never break the user-facing
 * request that triggered it.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Http_Proxy_Log {

    public const CRON_HOOK    = 'dsgo_apps_http_log_purge';
    private const TABLE_SLUG  = 'dsgo_apps_http_log';
    private const PATH_MAX    = 2000;
    private const DEFAULT_RETENTION_DAYS = 7;
    /** Delete in chunks so a long backlog doesn't lock the table on cron. */
    private const PURGE_BATCH_LIMIT = 5000;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Idempotent — safe to call on every activation and every upgrade.
     * dbDelta will no-op if the schema already matches.
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id      VARCHAR(64)  NOT NULL,
            host        VARCHAR(253) NOT NULL,
            method      VARCHAR(10)  NOT NULL,
            path        VARCHAR(2000) NOT NULL,
            status      SMALLINT UNSIGNED NOT NULL,
            duration_ms INT UNSIGNED NOT NULL,
            req_bytes   INT UNSIGNED NOT NULL,
            resp_bytes  INT UNSIGNED NOT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_app_created (app_id, created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Record one HTTP proxy attempt. Called from the bridge's 13-step
     * pipeline (step 13) regardless of outcome. Transport failure ⇒
     * status=0; permission/SSRF rejections logged by the bridge directly
     * with the relevant status (or 0 if no request was issued).
     */
    public static function log(
        string $app_id,
        string $host,
        string $method,
        string $path,
        int $status,
        int $duration_ms,
        int $req_bytes,
        int $resp_bytes,
    ): void {
        global $wpdb;
        // Path can exceed the column width with long query strings or
        // multi-segment REST URLs. Trim rather than throw — observability
        // must never bring down the request path.
        if (strlen($path) > self::PATH_MAX) {
            $path = substr($path, 0, self::PATH_MAX);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom log table; $wpdb->insert is the correct WP API for write-path log inserts; no caching layer applies to append-only writes
        $wpdb->insert(
            self::table_name(),
            [
                'app_id'      => $app_id,
                'host'        => $host,
                'method'      => $method,
                'path'        => $path,
                'status'      => $status,
                'duration_ms' => max(0, $duration_ms),
                'req_bytes'   => max(0, $req_bytes),
                'resp_bytes'  => max(0, $resp_bytes),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ],
            ['%s','%s','%s','%s','%d','%d','%d','%d','%s'],
        );
    }

    /**
     * Delete rows older than the configured retention window.
     * Filter: dsgo_apps_http_log_retention_days (int days, default 7).
     */
    public static function purge_expired(): void {
        global $wpdb;
        $days = (int) apply_filters('dsgo_apps_http_log_retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($days < 1) $days = 1;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table  = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table; $table built from $wpdb->prefix (not user input); log write: no caching applies to batch DELETE retention purges
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s LIMIT %d',
            $table,
            $cutoff,
            self::PURGE_BATCH_LIMIT,
        ));
    }
}
