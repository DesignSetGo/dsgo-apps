<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\WebhookIdempotency;
use WP_UnitTestCase;

/**
 * Tests for WebhookIdempotency — the duplicate-delivery suppressor.
 *
 * Providers like Stripe and GitHub redeliver events on transient
 * failures. The webhook handler reads the provider-supplied event
 * id, hashes it with sha256, and stores a 24-hour transient marker.
 * The next time the same event arrives, the handler short-circuits
 * with 200 `{ok:true, idempotent:true}` instead of re-invoking the
 * ability.
 *
 * Per-app + per-endpoint scoping keeps two apps that happen to
 * receive the same upstream event id from interfering with each
 * other. The event-id is sha256'd before being baked into the
 * transient key so high-cardinality / oddly-formatted event ids
 * never overflow WP's option-name length budget (191 bytes).
 */
final class WebhookIdempotencyTest extends WP_UnitTestCase {

    public function test_check_returns_false_for_unseen_event(): void {
        $this->assertFalse(
            WebhookIdempotency::check('myapp', 'stripe-events', 'evt_unseen_001'),
        );
    }

    public function test_record_then_check_returns_true(): void {
        WebhookIdempotency::record('myapp', 'stripe-events', 'evt_seen_001');
        $this->assertTrue(
            WebhookIdempotency::check('myapp', 'stripe-events', 'evt_seen_001'),
        );
    }

    public function test_record_is_scoped_per_app(): void {
        // Alpha records the event id; Beta with the same endpoint id
        // and the same event id must NOT be treated as a duplicate.
        WebhookIdempotency::record('alpha', 'stripe-events', 'evt_shared');
        $this->assertTrue(WebhookIdempotency::check('alpha', 'stripe-events', 'evt_shared'));
        $this->assertFalse(WebhookIdempotency::check('beta',  'stripe-events', 'evt_shared'));
    }

    public function test_record_is_scoped_per_endpoint(): void {
        // Same app, two different endpoints (e.g. stripe vs github)
        // happening to receive an event with the same id must not
        // cross-contaminate.
        WebhookIdempotency::record('myapp', 'stripe-events', 'evt_001');
        $this->assertTrue(WebhookIdempotency::check('myapp', 'stripe-events', 'evt_001'));
        $this->assertFalse(WebhookIdempotency::check('myapp', 'github-events', 'evt_001'));
    }

    public function test_long_event_id_does_not_overflow_option_name(): void {
        // WP option names are capped at 191 bytes. Stripe event ids are
        // ~30 chars; GitHub delivery ids are UUIDs; but providers can
        // ship arbitrarily long opaque ids. Hashing with sha256 keeps
        // the key length deterministic regardless of input size.
        $long = str_repeat('A', 4096);
        WebhookIdempotency::record('myapp', 'generic', $long);
        $this->assertTrue(WebhookIdempotency::check('myapp', 'generic', $long));
    }

    public function test_record_is_idempotent_for_the_same_event(): void {
        // Recording twice is a no-op semantically — the second record()
        // refreshes the TTL so a stubbornly-retrying producer keeps
        // the dedupe window alive without re-invoking the ability.
        WebhookIdempotency::record('myapp', 'stripe-events', 'evt_double');
        WebhookIdempotency::record('myapp', 'stripe-events', 'evt_double');
        $this->assertTrue(WebhookIdempotency::check('myapp', 'stripe-events', 'evt_double'));
    }

    public function test_empty_event_id_check_returns_false(): void {
        // sha256('') is a fixed digest. Without the empty-id guard,
        // every missing-id delivery would dedupe against every other
        // missing-id delivery — turning an upstream omission into a
        // silent cross-event collision. The handler is responsible for
        // rejecting missing ids; this class just refuses to participate.
        $this->assertFalse(WebhookIdempotency::check('myapp', 'stripe-events', ''));
    }

    public function test_empty_event_id_record_does_not_seed_cache(): void {
        // record('') must not write a transient — otherwise check('')
        // for a different (app, endpoint) tuple could collide via the
        // shared empty-string sha256.
        WebhookIdempotency::record('myapp', 'stripe-events', '');
        $this->assertFalse(WebhookIdempotency::check('myapp', 'stripe-events', ''));
        $this->assertFalse(WebhookIdempotency::check('other', 'stripe-events', ''));
    }
}
