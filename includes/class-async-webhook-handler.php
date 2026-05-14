<?php
/**
 * Async webhook delivery handler.
 *
 * Two surfaces:
 *
 *   enqueue($app_id, $endpoint_id, $idempotency_key, $body,
 *           $headers_json) — encrypts body + headers with the
 *     site's sodium key (Secret_Vault::encryption_key()), inserts a
 *     WebhookQueue row, returns the row id. Caller (WebhookHandler in
 *     Task 12) is expected to call wp_schedule_single_event(..., [$id])
 *     onto the ASYNC_HOOK so this handler picks the row up on the
 *     next cron tick.
 *
 *   run($queue_row_id) — pulls the row, decrypts, resolves the
 *     manifest's endpoint config to find the ability, invokes the
 *     ability. On success deletes the row and writes a status='ok'
 *     WebhookLog entry. On WP_Error / Throwable: increments the
 *     attempts counter, and if under the 3-attempt cap reschedules
 *     itself 5 minutes out; otherwise marks the row status='failed'
 *     and writes a terminal status='error' WebhookLog entry.
 *
 * Decryption failure is treated as terminal — re-running won't help
 * if the ciphertext itself is unreadable, and surfacing the dead
 * letter to the log immediately is more useful than the row sitting
 * in pending state until the daily-cleanup sweep notices.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AsyncWebhookHandler {

    /** The WP-cron hook the queue rows are scheduled against. */
    public const ASYNC_HOOK = 'dsgo_apps_webhook_async';

    /** Cap on how many times a row gets retried before terminal failure. */
    private const MAX_ATTEMPTS = 3;

    /** Backoff between retries, in seconds. */
    private const RETRY_DELAY_SECONDS = 300;

    /** Defensive cap on error_msg bytes recorded to the log + queue row. */
    private const ERROR_MSG_MAX = 1000;

    /**
     * Encrypt the inbound body + headers JSON and queue them for
     * async dispatch. Returns the row id; caller schedules
     * wp_schedule_single_event(..., [$id]).
     */
    public static function enqueue(
        string $app_id,
        string $endpoint_id,
        ?string $idempotency_key,
        string $body,
        string $headers_json,
    ): int {
        $key = Secret_Vault::encryption_key();

        $nonce1 = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $nonce2 = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return WebhookQueue::insert([
            'app_id'            => $app_id,
            'endpoint_id'       => $endpoint_id,
            'idempotency_key'   => $idempotency_key,
            'encrypted_body'    => $nonce1 . sodium_crypto_secretbox($body, $nonce1, $key),
            'encrypted_headers' => $nonce2 . sodium_crypto_secretbox($headers_json, $nonce2, $key),
            'received_at'       => current_time('mysql', true),
        ]);
    }

    /**
     * Pick up a queued delivery and dispatch it. Hook target for
     * `dsgo_apps_webhook_async`. Never throws.
     */
    public static function run(int $queue_row_id): void {
        $row = WebhookQueue::get($queue_row_id);
        if ($row === null) {
            // Row already processed or pruned — no-op, no log.
            return;
        }

        $start_ms = self::now_ms();
        $attempts = WebhookQueue::increment_attempts($queue_row_id);

        // Step 1: decrypt. Treat decryption failure as terminal —
        // retrying won't recover unreadable bytes.
        $decrypted = self::decrypt_row($row);
        if ($decrypted === null) {
            self::record_terminal_failure(
                $queue_row_id,
                $row,
                'decryption_failed',
                'Could not decrypt queued body or headers.',
                self::now_ms() - $start_ms,
            );
            return;
        }
        [$body, $headers] = $decrypted;

        // Step 2: resolve endpoint config to find the ability.
        $ability_name = self::resolve_ability($row['app_id'], $row['endpoint_id']);
        if ($ability_name === null) {
            self::record_terminal_failure(
                $queue_row_id,
                $row,
                'endpoint_config_missing',
                'Endpoint config not found for app + endpoint id.',
                self::now_ms() - $start_ms,
            );
            return;
        }

        // Step 3: resolve the ability instance.
        if (!function_exists('wp_has_ability') || !function_exists('wp_get_ability')) {
            self::handle_retryable_failure(
                $queue_row_id,
                $row,
                $attempts,
                'abilities_api_unavailable',
                'WP Abilities API not loaded yet.',
                self::now_ms() - $start_ms,
            );
            return;
        }
        AbilitiesPublisher::register_all();
        if (!wp_has_ability($ability_name)) {
            self::handle_retryable_failure(
                $queue_row_id,
                $row,
                $attempts,
                'ability_not_registered',
                sprintf('Ability %s is not registered (companion plugin missing?).', $ability_name),
                self::now_ms() - $start_ms,
            );
            return;
        }
        $ability = wp_get_ability($ability_name);
        if (!$ability) {
            self::handle_retryable_failure(
                $queue_row_id,
                $row,
                $attempts,
                'ability_not_registered',
                sprintf('Ability %s lookup returned null.', $ability_name),
                self::now_ms() - $start_ms,
            );
            return;
        }

        // Step 4: execute. The input shape mirrors what the sync
        // handler (Task 12) builds — body decoded as JSON when
        // possible, plus the raw bytes for HMAC re-verification,
        // plus the safe headers map.
        $input = [
            'body'    => json_decode($body, true),
            'raw'     => $body,
            'headers' => json_decode($headers, true) ?: [],
            'method'  => 'POST',
        ];
        try {
            $result = $ability->execute($input);
        } catch (\Throwable $e) {
            self::handle_retryable_failure(
                $queue_row_id,
                $row,
                $attempts,
                'ability_exception',
                $e->getMessage(),
                self::now_ms() - $start_ms,
            );
            return;
        }

        $duration = self::now_ms() - $start_ms;

        if (is_wp_error($result)) {
            // WP_Ability wraps thrown exceptions as
            // ability_callback_exception — treat it like our own
            // ability_exception code in the audit. Either way, it's
            // retryable.
            self::handle_retryable_failure(
                $queue_row_id,
                $row,
                $attempts,
                $result->get_error_code() === 'ability_callback_exception'
                    ? 'ability_exception'
                    : 'ability_returned_wp_error',
                $result->get_error_message(),
                $duration,
            );
            return;
        }

        // Step 5: success. Record the idempotency key so a retried
        // delivery of the same signed event is short-circuited at the
        // sync handler's check (step 5 of WebhookHandler::handle). The
        // sync path records here on success; the async path used to
        // skip this — meaning Stripe / GitHub / Slack retries of an
        // already-completed event would re-execute the bound ability.
        // record() is a no-op for empty event ids, so unconditional.
        WebhookIdempotency::record(
            $row['app_id'],
            $row['endpoint_id'],
            (string) ($row['idempotency_key'] ?? ''),
        );
        // Drop the row so the encrypted payload doesn't linger past
        // dispatch.
        WebhookQueue::delete($queue_row_id);
        WebhookLog::insert([
            'app_id'      => $row['app_id'],
            'endpoint_id' => $row['endpoint_id'],
            'received_at' => $row['received_at'],
            'duration_ms' => $duration,
            'http_status' => 200,
            'status'      => 'ok',
            'async'       => true,
            'error_code'  => null,
            'error_msg'   => null,
        ]);
    }

    /**
     * Decrypt the row's body + headers. Returns null on any failure
     * (ciphertext too short, sodium_crypto_secretbox_open returns false).
     *
     * @param array<string, mixed> $row
     * @return array{0:string, 1:string}|null
     */
    private static function decrypt_row(array $row): ?array {
        $key  = Secret_Vault::encryption_key();
        $nlen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        $body_blob = $row['encrypted_body'] ?? '';
        $hdrs_blob = $row['encrypted_headers'] ?? '';
        if (!is_string($body_blob) || !is_string($hdrs_blob)
            || strlen($body_blob) <= $nlen
            || strlen($hdrs_blob) <= $nlen
        ) {
            return null;
        }

        $body = sodium_crypto_secretbox_open(
            substr($body_blob, $nlen),
            substr($body_blob, 0, $nlen),
            $key,
        );
        $headers = sodium_crypto_secretbox_open(
            substr($hdrs_blob, $nlen),
            substr($hdrs_blob, 0, $nlen),
            $key,
        );
        if ($body === false || $headers === false) {
            return null;
        }
        return [$body, $headers];
    }

    /**
     * Read the manifest from the app's dsgo_app post and resolve the
     * `webhooks.endpoints[]` entry whose id matches $endpoint_id.
     * Returns the bound ability name, or null when the app or endpoint
     * has been deleted since enqueue.
     */
    private static function resolve_ability(string $app_id, string $endpoint_id): ?string {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'name'           => $app_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        if ($posts === []) return null;
        $manifest_arr = get_post_meta($posts[0], 'dsgo_apps_manifest', true);
        if (!is_array($manifest_arr)) return null;
        $endpoints = $manifest_arr['webhooks']['endpoints'] ?? null;
        if (!is_array($endpoints)) return null;
        foreach ($endpoints as $endpoint) {
            if (is_array($endpoint)
                && ($endpoint['id'] ?? null) === $endpoint_id
                && isset($endpoint['ability'])
                && is_string($endpoint['ability'])
            ) {
                return $endpoint['ability'];
            }
        }
        return null;
    }

    /**
     * Retryable failure path: if we haven't hit MAX_ATTEMPTS, queue
     * a single-event retry RETRY_DELAY_SECONDS out. Otherwise mark
     * the row terminally failed and write a status='error' log row.
     *
     * @param array<string, mixed> $row
     */
    private static function handle_retryable_failure(
        int $queue_row_id,
        array $row,
        int $attempts,
        string $error_code,
        string $error_msg,
        int $duration_ms,
    ): void {
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::record_terminal_failure($queue_row_id, $row, $error_code, $error_msg, $duration_ms);
            return;
        }
        wp_schedule_single_event(
            time() + self::RETRY_DELAY_SECONDS,
            self::ASYNC_HOOK,
            [$queue_row_id],
        );
    }

    /**
     * Terminal failure path: mark the queue row failed AND write a
     * status='error' WebhookLog entry so ops sees the dead letter.
     *
     * @param array<string, mixed> $row
     */
    private static function record_terminal_failure(
        int $queue_row_id,
        array $row,
        string $error_code,
        string $error_msg,
        int $duration_ms,
    ): void {
        WebhookQueue::mark_failed($queue_row_id, substr($error_msg, 0, self::ERROR_MSG_MAX));
        WebhookLog::insert([
            'app_id'      => $row['app_id'],
            'endpoint_id' => $row['endpoint_id'],
            'received_at' => $row['received_at'],
            'duration_ms' => $duration_ms,
            'http_status' => 500,
            'status'      => 'error',
            'async'       => true,
            'error_code'  => $error_code,
            'error_msg'   => substr($error_msg, 0, self::ERROR_MSG_MAX),
        ]);
    }

    private static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }
}
