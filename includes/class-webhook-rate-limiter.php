<?php
/**
 * Per-endpoint webhook rate limiter.
 *
 * Counts requests in a **fixed** minute-bucket window. One transient per
 * (app_id, endpoint_id, minute-bucket); the bucket flips at every
 * 60-second boundary. Because the window is fixed (not sliding), an
 * attacker straddling a bucket boundary can in principle send up to
 * 2× the limit in a single second — acceptable for v1's
 * defensive-throttle posture, surfaced here so a future maintainer
 * doesn't skip a real fix assuming "sliding" semantics.
 *
 * The transient TTL is 120s so a request landing in the last second
 * of one minute and the first second of the next can each be counted
 * against their own bucket without one prematurely expiring.
 *
 * **Race condition (intentional, documented):**
 * try_acquire() is a non-atomic read-modify-write on a wp_options-
 * backed transient. Under concurrent requests, two callers reading
 * the same count can both write count+1, allowing a small (≤ N
 * concurrent workers) over-shoot of the limit. This is acceptable
 * for the defensive-throttle use case — the goal is to slow a flood,
 * not enforce an exact quota. If atomicity matters in v2, swap to
 * `wp_cache_incr` when a persistent object cache is present (Redis,
 * Memcached); the wp_options fallback inherits the same race.
 *
 * The limit comes from the manifest's
 * `webhooks.endpoints[].rate_limit_per_minute` field (validated to
 * 1–600 by Manifest::validate_webhooks). The handler asks the limiter
 * "may I accept one more request this minute, given limit N?" — true
 * means accept-and-count, false means 429.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookRateLimiter {

    /**
     * Transient TTL. Two minutes covers a request landing in the last
     * second of one bucket and the first second of the next without
     * letting old counters linger past their usefulness.
     */
    private const TTL_SECONDS = 120;

    /**
     * Best-effort token acquire for the current minute bucket. Returns
     * true when the caller may proceed (and the counter has been
     * incremented); false when the current count already meets or
     * exceeds the limit.
     *
     * Renamed from check_and_increment in 2026-05 to call out the
     * non-atomic semantics — see the class header for the race
     * condition note. Callers MUST treat a true return as a
     * permission slip, not a strict guarantee.
     *
     * The check is `count >= limit` (not `>`), so a misconfigured
     * limit of 0 rejects every request — there's no way to opt out
     * of rate limiting by setting the limit to 0. The manifest
     * validator already enforces limit ≥ 1, but a defensive bound
     * here keeps callers honest.
     */
    public static function try_acquire(string $app_id, string $endpoint_id, int $limit): bool {
        $key   = self::transient_key($app_id, $endpoint_id);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, self::TTL_SECONDS);
        return true;
    }

    private static function transient_key(string $app_id, string $endpoint_id): string {
        // Minute bucket — falls into the same bucket from xx:00.000
        // through xx:59.999. Key-length budget: _transient_timeout_
        // prefix (19) + 'dsgo_apps_wh_rl_' (16) + 'bucket' digits (~10)
        // + 3 underscores = ~48 fixed bytes, leaving ~143 bytes for
        // app_id + endpoint_id. Both capped at 64 chars; fits comfortably.
        $bucket = (int) (time() / 60);
        return "dsgo_apps_wh_rl_{$app_id}_{$endpoint_id}_{$bucket}";
    }
}
