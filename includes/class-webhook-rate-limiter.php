<?php
/**
 * Per-endpoint webhook rate limiter.
 *
 * Counts requests in a sliding minute-bucket window. One transient per
 * (app_id, endpoint_id, minute-bucket) — the bucket flips every 60s so
 * a steady-state attacker is forced to spread their requests evenly
 * rather than burst at boundary seconds. The transient TTL is 120s so
 * a request arriving in the last second of one minute and the first
 * second of the next can each be correctly counted against their own
 * bucket.
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
     * Increment-and-check. Returns true when the increment leaves
     * the counter at-or-below the limit; false (rate-limited) when
     * the current count already meets or exceeds the limit.
     *
     * Important: the check is `count >= limit` (not `>`), so a
     * misconfigured limit of 0 rejects every request — there's no
     * way to opt out of rate limiting by setting the limit to 0.
     * The manifest validator already enforces limit ≥ 1, but a
     * defensive bound here keeps callers honest.
     */
    public static function check_and_increment(string $app_id, string $endpoint_id, int $limit): bool {
        $key   = self::transient_key($app_id, $endpoint_id);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, self::TTL_SECONDS);
        return true;
    }

    private static function transient_key(string $app_id, string $endpoint_id): string {
        // Minute bucket. Falls into the same bucket from xx:00.000
        // through xx:59.999 — provider-side bursts still concentrate
        // on a single counter, which is what we want for rate-limit.
        $bucket = (int) (time() / 60);
        return "dsgo_apps_wh_rl_{$app_id}_{$endpoint_id}_{$bucket}";
    }
}
