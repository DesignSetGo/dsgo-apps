<?php
/**
 * Shared fixed-window rate-limiter mechanism.
 *
 * Four subsystems independently re-implemented the same transient-backed
 * fixed-window counter: `WebhookRateLimiter`, `Http_Proxy_Bridge`,
 * `MediaBridge`, and `EmailBridge`. Each computed its own bucket key, limit,
 * and TTL, but the read-modify-write core — `get_transient` → compare against
 * the limit → `set_transient($count + 1)` — was byte-identical.
 *
 * This class owns ONLY that core mechanism. Callers still compute their own
 * key, limit, and TTL (which legitimately differ: 90s for the HTTP proxy
 * per-minute bucket, HOUR+60 for the media/email per-hour buckets, 120s for
 * the webhook per-minute bucket). Nothing about the windows is unified — only
 * the duplicated counter logic.
 *
 * **Race condition (intentional, documented):** `try_acquire()` is a
 * non-atomic read-modify-write on a wp_options-backed transient. Under
 * concurrent requests two callers reading the same count can both write
 * count+1, allowing a small (≤ N concurrent workers) over-shoot of the
 * limit. This matches the prior per-class behavior exactly — the goal is a
 * defensive throttle, not an exact quota.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Rate_Limiter {

    /**
     * Best-effort token acquire for a fixed-window transient bucket.
     *
     * Returns true when the caller may proceed (and the counter has been
     * incremented); false when the current count already meets or exceeds
     * `$limit`. The check is `count >= limit` (not `>`), so `$limit` of 0
     * rejects every request — there is no opt-out by setting the limit to 0.
     *
     * @param string $key   Fully-resolved transient key (caller owns bucketing).
     * @param int    $limit Maximum count permitted within the window.
     * @param int    $ttl   Transient TTL in seconds.
     */
    public static function try_acquire(string $key, int $limit, int $ttl): bool {
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $ttl);
        return true;
    }
}
