<?php
/**
 * Async webhook delivery queue.
 *
 * Stores requests for endpoints declared `async: true` in the manifest.
 * The synchronous handler (Task 12) accepts the request, encrypts the
 * body + headers via Secret_Vault's per-app key, inserts a row here,
 * and returns 200 `{ok:true, queued:true}` immediately. A scheduled
 * `dsgo_apps_webhook_async` event fires AsyncWebhookHandler::run()
 * (Task 11) with the row id, which pulls the row, decrypts, invokes
 * the ability, then either deletes the row (success), reschedules
 * itself with exponential backoff (transient failure under 3 attempts),
 * or marks status='failed' (3 attempts exhausted).
 *
 * Bodies + headers are LONGBLOB to fit Stripe's 250 KB cap with
 * sodium ciphertext overhead. Encryption happens upstream — this
 * class stores opaque bytes.
 *
 * Schema lifted directly from the cron+webhooks plan spec.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookQueue {

    private const TABLE_SLUG = 'dsgo_apps_webhook_queue';

    /** Defensive cap so a runaway upstream error message doesn't blow up the row. */
    private const ERROR_MSG_MAX = 2000;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Idempotent — safe to call on every activation and every upgrade.
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        // status uses VARCHAR rather than ENUM so dbDelta's reformatting
        // on upgrade can't silently drop values. Matches CronLog +
        // WebhookLog posture.
        $sql = "CREATE TABLE $table (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id            VARCHAR(64)     NOT NULL,
            endpoint_id       VARCHAR(64)     NOT NULL,
            idempotency_key   VARCHAR(255)    NULL,
            encrypted_body    LONGBLOB        NOT NULL,
            encrypted_headers LONGBLOB        NOT NULL,
            received_at       DATETIME        NOT NULL,
            attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status            VARCHAR(10)     NOT NULL DEFAULT 'pending',
            error_msg         TEXT            NULL,
            PRIMARY KEY (id),
            KEY app_ep  (app_id, endpoint_id),
            KEY status  (status)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Insert a new queue row. Returns the row id for the caller to
     * schedule the async event against.
     *
     * @param array{
     *   app_id:string,
     *   endpoint_id:string,
     *   idempotency_key:?string,
     *   encrypted_body:string,
     *   encrypted_headers:string,
     *   received_at:string,
     * } $row
     */
    public static function insert(array $row): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom queue table; $wpdb->insert is the correct WP API for write-path queue inserts; no caching layer applies to append-only writes
        $ok = $wpdb->insert(
            self::table_name(),
            [
                'app_id'            => $row['app_id'],
                'endpoint_id'       => $row['endpoint_id'],
                'idempotency_key'   => $row['idempotency_key'] ?? null,
                'encrypted_body'    => $row['encrypted_body'],
                'encrypted_headers' => $row['encrypted_headers'],
                'received_at'       => $row['received_at'],
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
        );
        if ($ok === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG gated; intentional for production debugging of webhook queue insert failures
                error_log(sprintf(
                    'dsgo_apps: WebhookQueue::insert failed (app=%s endpoint=%s): %s',
                    $row['app_id'] ?? '?',
                    $row['endpoint_id'] ?? '?',
                    $wpdb->last_error,
                ));
            }
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Load a queue row by id. Returns null when the row no longer exists
     * (caller deleted it after success, or the prune sweep removed it).
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom queue table; $table built from $wpdb->prefix (not user input); queue dequeue: single-row fetch by primary key; caching a mutable queue row would serve stale state
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $table,
            $id,
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Increment the row's attempts counter and return the new value.
     * Used by AsyncWebhookHandler to gate the 3-retry cap.
     */
    public static function increment_attempts(int $id): int {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom queue table; $table built from $wpdb->prefix (not user input); queue write: atomic attempt counter increment; no caching applies to mutable queue state
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET attempts = attempts + 1 WHERE id = %d',
            $table,
            $id,
        ));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom queue table; $table built from $wpdb->prefix (not user input); queue read: re-read attempts after increment; must not serve cached pre-increment value
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT attempts FROM %i WHERE id = %d',
            $table,
            $id,
        ));
    }

    /**
     * Mark a row's terminal-failure state. The row stays in the table
     * so the admin UI can surface failed deliveries and the operator
     * can decide what to do (replay, delete, ignore).
     */
    public static function mark_failed(int $id, string $error_msg): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom queue table; $wpdb->update is the correct WP API for write-path queue status updates; no caching layer applies to mutable queue state
        $wpdb->update(
            self::table_name(),
            [
                'status'    => 'failed',
                'error_msg' => substr($error_msg, 0, self::ERROR_MSG_MAX),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Delete a row. Called by AsyncWebhookHandler::run on successful
     * dispatch — the encrypted payload should not linger past success.
     */
    public static function delete(int $id): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom queue table; $wpdb->delete is the correct WP API for removing processed queue rows; no caching layer applies to this cleanup path
        $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
    }
}
