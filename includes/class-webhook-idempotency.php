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

    /**
     * 72-hour TTL covers Stripe's full exponential retry schedule
     * (Stripe attempts redelivery for up to 3 days). GitHub retries
     * for ~8 hours and Slack repeats until 3xx — both comfortably
     * inside this window. record() seeds at first delivery; check()
     * refreshes the TTL on every cache hit so a long-quiet event
     * stream followed by a late retry still hits the cache.
     */
    private const TTL_SECONDS = 3 * DAY_IN_SECONDS;

    /**
     * Has this event already been delivered through this endpoint?
     * Returns true when a prior record() call seeded the transient.
     *
     * On hit, refresh the TTL — providers whose retry schedule
     * stretches past the original record() timestamp still get
     * deduped, and a steady stream of redeliveries keeps the
     * window from collapsing under the original 72h.
     *
     * Empty event_id is always treated as "unseen" (returns false).
     * sha256 of '' is a fixed digest, so without this guard every
     * delivery missing an event id would collapse to one transient
     * key and dedupe against unrelated events.
     */
    public static function check(string $app_id, string $endpoint_id, string $event_id): bool {
        if ($event_id === '') {
            return false;
        }
        $key   = self::transient_key($app_id, $endpoint_id, $event_id);
        $value = get_transient($key);
        if ($value === false) {
            return false;
        }
        set_transient($key, 1, self::TTL_SECONDS);
        return true;
    }

    /**
     * Mark this event as delivered. Subsequent check() calls within
     * TTL_SECONDS return true. Calling record() twice for the same
     * (app, endpoint, event) refreshes the TTL — semantically a
     * no-op, operationally a way to extend the dedupe window for
     * stubbornly-retrying producers.
     *
     * Empty event_id is silently ignored — the upstream handler
     * (WebhookHandler in Task 12) is responsible for surfacing the
     * missing-id condition; we just refuse to write a meaningless
     * key.
     */
    public static function record(string $app_id, string $endpoint_id, string $event_id): void {
        if ($event_id === '') {
            return;
        }
        set_transient(self::transient_key($app_id, $endpoint_id, $event_id), 1, self::TTL_SECONDS);
    }

    private static function transient_key(string $app_id, string $endpoint_id, string $event_id): string {
        // Key-length budget: WP's option name column is VARCHAR(191).
        // _transient_timeout_ prefix (19) + 'dsgo_apps_wh_idem_' (18) +
        // sha256 hex (64) + two underscores = 103 fixed bytes, leaving
        // 88 bytes for app_id + endpoint_id. Both manifest fields are
        // capped at 64 chars, so total fits with room to spare. Do
        // not widen the static prefix above without re-checking this.
        $hash = hash('sha256', $event_id);
        return "dsgo_apps_wh_idem_{$app_id}_{$endpoint_id}_{$hash}";
    }
}
