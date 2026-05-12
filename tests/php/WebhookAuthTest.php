<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Secret_Vault;
use DSGo_Apps\WebhookAuth;
use WP_UnitTestCase;

/**
 * Tests for Task 8 of the cron + webhooks plan: WebhookAuth.
 *
 * Covers the five auth schemes the manifest validator accepts:
 *
 *   hmac-sha256 + scheme=stripe   — Stripe-Signature header (t=…,v1=…)
 *   hmac-sha256 + scheme=github   — X-Hub-Signature-256 (sha256=…)
 *   hmac-sha256 + scheme=slack    — X-Slack-Signature (v0=…) + X-Slack-Request-Timestamp
 *   hmac-sha256 + scheme=generic  — X-Webhook-Signature (raw sha256 hex)
 *   bearer                        — Authorization: Bearer <secret>
 *
 * Every comparison uses hash_equals() to keep timing constant-time.
 * Every failure surfaces a generic webhook_auth_failed code; the
 * audit log gets the row, the response stays vague (don't tell
 * unauthenticated callers WHICH step failed).
 *
 * Secrets resolve through Secret_Vault::get($app_id, $alias). When
 * the alias isn't configured, verify() returns webhook_secret_not_set
 * so the admin can be prompted to fill in the secret.
 */
final class WebhookAuthTest extends WP_UnitTestCase {

    private const APP_ID = 'webhook-test';
    private const ALIAS  = 'STRIPE_SIGNING_SECRET';
    /** A high-entropy literal so test fixtures aren't predictable. */
    private const SECRET = 'whsec_test_e9f2a1c3d4b5e6f7a8b9c0d1e2f3a4b5';

    public function set_up(): void {
        parent::set_up();
        Secret_Vault::set(self::APP_ID, self::ALIAS, self::SECRET);
    }

    public function tear_down(): void {
        Secret_Vault::delete_all(self::APP_ID);
        parent::tear_down();
    }

    // ===== hmac-sha256 stripe =====

    public function test_hmac_stripe_valid_signature_passes(): void {
        $body = '{"id":"evt_test_1"}';
        $ts   = time();
        $sig  = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            $body,
            ['stripe-signature' => "t={$ts},v1={$sig}"],
            self::APP_ID,
        ));
    }

    public function test_hmac_stripe_wrong_signature_fails(): void {
        $body = '{"id":"evt_test_1"}';
        $ts   = time();
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            $body,
            ['stripe-signature' => "t={$ts},v1=" . str_repeat('a', 64)],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('webhook_auth_failed', $result->get_error_code());
    }

    public function test_hmac_stripe_expired_timestamp_fails(): void {
        $body = '{"id":"evt_test_1"}';
        // 10 minutes ago — outside the 5-minute Stripe replay window.
        $ts   = time() - 600;
        $sig  = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            $body,
            ['stripe-signature' => "t={$ts},v1={$sig}"],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('webhook_auth_failed', $result->get_error_code());
    }

    public function test_hmac_stripe_malformed_header_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            'body',
            ['stripe-signature' => 'this is not a stripe signature'],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('webhook_auth_failed', $result->get_error_code());
    }

    public function test_hmac_stripe_missing_header_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            'body',
            [],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ===== hmac-sha256 github =====

    public function test_hmac_github_valid_signature_passes(): void {
        $body = '{"action":"opened"}';
        $sig  = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'github'),
            $body,
            ['x-hub-signature-256' => $sig],
            self::APP_ID,
        ));
    }

    public function test_hmac_github_wrong_signature_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'github'),
            '{"action":"opened"}',
            ['x-hub-signature-256' => 'sha256=' . str_repeat('b', 64)],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_hmac_github_missing_prefix_fails(): void {
        // GitHub always sends the `sha256=` prefix. A bare hex digest
        // must be rejected so callers can't downgrade to schemes that
        // don't include the algorithm identifier.
        $body = '{"action":"opened"}';
        $bare = hash_hmac('sha256', $body, self::SECRET);
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'github'),
            $body,
            ['x-hub-signature-256' => $bare],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ===== hmac-sha256 slack =====

    public function test_hmac_slack_valid_signature_passes(): void {
        $body = 'token=abc&team_id=T0001';
        $ts   = (string) time();
        $sig  = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'slack'),
            $body,
            [
                'x-slack-request-timestamp' => $ts,
                'x-slack-signature'         => $sig,
            ],
            self::APP_ID,
        ));
    }

    public function test_hmac_slack_missing_v0_prefix_fails(): void {
        // Mirror GitHub's downgrade guard: a bare hex digest without the
        // `v0=` version prefix must be rejected, so a future `v1=` scheme
        // can't be silently accepted by length coincidence.
        $body = 'token=abc';
        $ts   = (string) time();
        $bare = hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'slack'),
            $body,
            [
                'x-slack-request-timestamp' => $ts,
                'x-slack-signature'         => $bare,  // missing `v0=`
            ],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_hmac_slack_expired_timestamp_fails(): void {
        $body = 'token=abc';
        $ts   = (string) (time() - 600);  // 10 min ago, outside 5-min window
        $sig  = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", self::SECRET);
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'slack'),
            $body,
            [
                'x-slack-request-timestamp' => $ts,
                'x-slack-signature'         => $sig,
            ],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ===== hmac-sha256 generic =====

    public function test_hmac_generic_valid_signature_passes(): void {
        $body = '{"event":"sale"}';
        $sig  = hash_hmac('sha256', $body, self::SECRET);
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'generic'),
            $body,
            ['x-webhook-signature' => $sig],
            self::APP_ID,
        ));
    }

    public function test_hmac_generic_wrong_signature_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'generic'),
            '{"event":"sale"}',
            ['x-webhook-signature' => str_repeat('c', 64)],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ===== bearer =====

    public function test_bearer_valid_token_passes(): void {
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('bearer'),
            'body-ignored-for-bearer',
            ['authorization' => 'Bearer ' . self::SECRET],
            self::APP_ID,
        ));
    }

    public function test_bearer_wrong_token_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('bearer'),
            'body',
            ['authorization' => 'Bearer not-the-right-token'],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_bearer_missing_prefix_fails(): void {
        // A bare token without the "Bearer " prefix must be rejected.
        $result = WebhookAuth::verify(
            $this->endpoint('bearer'),
            'body',
            ['authorization' => self::SECRET],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_bearer_missing_header_fails(): void {
        $result = WebhookAuth::verify(
            $this->endpoint('bearer'),
            'body',
            [],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * RFC 7235 §2.1 makes auth-scheme tokens case-insensitive. Producers
     * may legitimately send `Bearer`, `bearer`, or `BEARER`; the verifier
     * must accept any case for the scheme name while still treating the
     * token bytes as case-sensitive.
     *
     * @dataProvider bearer_scheme_cases
     */
    public function test_bearer_scheme_is_case_insensitive(string $scheme_token): void {
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('bearer'),
            'body',
            ['authorization' => $scheme_token . ' ' . self::SECRET],
            self::APP_ID,
        ));
    }

    /** @return array<string, array{0:string}> */
    public static function bearer_scheme_cases(): array {
        return [
            'lowercase'  => ['bearer'],
            'titlecase'  => ['Bearer'],
            'uppercase'  => ['BEARER'],
            'mixed-case' => ['BeArEr'],
        ];
    }

    public function test_bearer_token_remains_case_sensitive(): void {
        // The token's bytes must compare exactly even when the scheme
        // is case-insensitive — otherwise constant-time compare is
        // pointless. Use a secret with mixed case to prove the point.
        Secret_Vault::set(self::APP_ID, 'CASE_TOKEN_ALIAS', 'CaseSensitive_token_123');
        $endpoint = $this->endpoint('bearer');
        $endpoint['auth']['secret_alias'] = 'CASE_TOKEN_ALIAS';
        $result = WebhookAuth::verify(
            $endpoint,
            'body',
            ['authorization' => 'bearer casesensitive_token_123'],  // wrong case in token
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ===== Secret vault gating =====

    /**
     * The webhook_secret_not_set surfaces from a single shared code path,
     * but every scheme must inherit the operator-visible error code so
     * the admin's "configure missing secret" prompt fires regardless of
     * which auth shape the manifest declared.
     *
     * @dataProvider every_auth_shape
     * @param array<string, mixed> $auth
     */
    public function test_secret_not_set_returns_specific_error_for_every_scheme(array $auth): void {
        $auth['secret_alias'] = 'UNCONFIGURED_FOR_THIS_TEST';
        $result = WebhookAuth::verify(
            ['auth' => $auth],
            'body',
            // Headers can be empty — the secret check fires before any
            // signature parsing, so the request never reaches scheme-
            // specific header lookups.
            [],
            self::APP_ID,
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('webhook_secret_not_set', $result->get_error_code());
    }

    /** @return array<string, array{0:array<string, mixed>}> */
    public static function every_auth_shape(): array {
        return [
            'hmac-sha256 / stripe'  => [['type' => 'hmac-sha256', 'scheme' => 'stripe']],
            'hmac-sha256 / github'  => [['type' => 'hmac-sha256', 'scheme' => 'github']],
            'hmac-sha256 / slack'   => [['type' => 'hmac-sha256', 'scheme' => 'slack']],
            'hmac-sha256 / generic' => [['type' => 'hmac-sha256', 'scheme' => 'generic']],
            'bearer'                => [['type' => 'bearer']],
        ];
    }

    public function test_verify_normalizes_uppercase_header_keys(): void {
        // The docblock contract says verify() takes lowercase-keyed
        // headers, but a defensive normalization at entry keeps a
        // caller passing PSR-7-style title-cased names (e.g. raw
        // $_SERVER converted via wp_get_server_protocol-adjacent
        // helpers) from silently degrading to webhook_auth_failed.
        $body = '{"id":"evt"}';
        $ts   = time();
        $sig  = hash_hmac('sha256', "{$ts}.{$body}", self::SECRET);
        $this->assertTrue(WebhookAuth::verify(
            $this->endpoint('hmac-sha256', 'stripe'),
            $body,
            // Mixed-case header names — should still resolve.
            ['Stripe-Signature' => "t={$ts},v1={$sig}"],
            self::APP_ID,
        ));
    }

    // ===== helpers =====

    /** @return array<string, mixed> */
    private function endpoint(string $type, ?string $scheme = null): array {
        $auth = [
            'type'         => $type,
            'secret_alias' => self::ALIAS,
        ];
        if ($scheme !== null) {
            $auth['scheme'] = $scheme;
        }
        return ['auth' => $auth];
    }
}
