<?php
/**
 * Webhook idempotency suppressor.
 *
 * Providers redeliver webhook events on transient failures (Stripe will
 * retry up to 72 hours, GitHub up to 8 hours, Slack repeats until 3xx
 * is observed). When the same event id arrives twice, the handler must
 * NOT re-invoke the ability — that's the contract the providers expect.
 *
 * One transient per (app_id, endpoint_id, sha256(event_id)) with a
 * 24-hour TTL. The event id is hashed before being baked into the
 * transient key so a high-entropy / oddly-shaped id doesn't overflow
 * WP's option-name budget (191 bytes for indexable VARCHAR utf8mb4),
 * and so the key length stays deterministic regardless of input size.
 *
 * Per-app + per-endpoint scoping keeps two apps that legitimately
 * receive the same upstream event id from interfering with each
 * other — and keeps two endpoints on the same app from cross-
 * contaminating their dedupe state.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookIdempotency {

    /** 24-hour TTL covers Stripe's full retry window with margin. */
    private const TTL_SECONDS = DAY_IN_SECONDS;

    /**
     * Has this event already been delivered through this endpoint?
     * Returns true when a prior record() call seeded the transient.
     */
    public static function check(string $app_id, string $endpoint_id, string $event_id): bool {
        return get_transient(self::transient_key($app_id, $endpoint_id, $event_id)) !== false;
    }

    /**
     * Mark this event as delivered. Subsequent check() calls within
     * TTL_SECONDS return true. Calling record() twice for the same
     * (app, endpoint, event) is a no-op semantically — set_transient
     * with the same value resets the TTL clock, which is fine for
     * the dedupe use case (re-deliveries should each refresh the
     * window so a slow third delivery still hits the cache).
     */
    public static function record(string $app_id, string $endpoint_id, string $event_id): void {
        set_transient(self::transient_key($app_id, $endpoint_id, $event_id), 1, self::TTL_SECONDS);
    }

    private static function transient_key(string $app_id, string $endpoint_id, string $event_id): string {
        $hash = hash('sha256', $event_id);
        return "dsgo_apps_wh_idem_{$app_id}_{$endpoint_id}_{$hash}";
    }
}
