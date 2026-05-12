<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\WebhookRateLimiter;
use WP_UnitTestCase;

/**
 * Tests for WebhookRateLimiter — per-app, per-endpoint, per-minute counter.
 *
 * One transient per (app_id, endpoint_id, current-minute-bucket). Each
 * accepted request increments the counter; when the counter reaches
 * the configured limit, subsequent requests within the same minute
 * are rejected. The transient TTL is 120s so a request landing in
 * the last second of one minute and the first second of the next
 * gets counted correctly during the bucket transition.
 *
 * The limit is per-minute, not per-request-class, so an attacker
 * who spreads a flood across all endpoints under one app still
 * concentrates load on the rate-limited path the moment they pick
 * one — the counter scopes by endpoint.
 */
final class WebhookRateLimiterTest extends WP_UnitTestCase {

    public function test_allows_request_under_limit(): void {
        $this->assertTrue(
            WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 60),
        );
    }

    public function test_allows_exactly_n_requests_then_rejects(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue(
                WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 5),
                "request #$i within the limit should be allowed",
            );
        }
        $this->assertFalse(
            WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 5),
            'request beyond the limit must be rejected',
        );
    }

    public function test_counter_is_scoped_per_app(): void {
        // App alpha exhausts its budget; app beta with the same
        // endpoint id and the same limit must still be allowed.
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(
                WebhookRateLimiter::try_acquire('alpha', 'stripe-events', 3),
            );
        }
        $this->assertFalse(
            WebhookRateLimiter::try_acquire('alpha', 'stripe-events', 3),
        );
        $this->assertTrue(
            WebhookRateLimiter::try_acquire('beta', 'stripe-events', 3),
            'beta should not inherit alpha\'s exhausted counter',
        );
    }

    public function test_counter_is_scoped_per_endpoint(): void {
        // Same app, two endpoints share the budget independently.
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue(
                WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 2),
            );
        }
        $this->assertFalse(
            WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 2),
        );
        $this->assertTrue(
            WebhookRateLimiter::try_acquire('myapp', 'github-events', 2),
            'github-events endpoint should have its own counter',
        );
    }

    public function test_zero_limit_rejects_immediately(): void {
        // A misconfigured endpoint with limit=0 should reject every
        // request rather than allow one through (count starts at 0
        // and the check is "if (count >= limit) reject").
        $this->assertFalse(
            WebhookRateLimiter::try_acquire('myapp', 'stripe-events', 0),
        );
    }
}
