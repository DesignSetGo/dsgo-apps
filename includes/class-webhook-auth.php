<?php
/**
 * Webhook authentication verifier.
 *
 * Five schemes matching the manifest validator's `webhooks.endpoints[].auth`:
 *
 *   - hmac-sha256 + scheme=stripe   Stripe-Signature header (t=...,v1=...)
 *   - hmac-sha256 + scheme=github   X-Hub-Signature-256 header (sha256=...)
 *   - hmac-sha256 + scheme=slack    X-Slack-Signature header (v0=...)
 *                                    + X-Slack-Request-Timestamp
 *   - hmac-sha256 + scheme=generic  X-Webhook-Signature header (raw sha256 hex)
 *   - bearer                        Authorization: Bearer <secret>
 *
 * Every comparison uses hash_equals() so a malicious caller can't probe the
 * expected signature byte-by-byte via timing. The Stripe + Slack schemes
 * additionally enforce a 5-minute replay window via the request timestamp.
 *
 * All failure paths surface the same error code (`webhook_auth_failed`)
 * regardless of which step rejected the request — auth errors must NOT
 * reveal whether the failure was a wrong signature, a missing header, or
 * an expired timestamp. The audit log gets the detail; the HTTP response
 * stays vague.
 *
 * The one exception is `webhook_secret_not_set`, which is operator-visible
 * (the admin needs to be told to configure the missing alias).
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookAuth {

    /** Stripe and Slack both use a 5-minute replay window. */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Header-name constants. Lowercase because verify() normalizes the
     * inbound header map to lowercase keys before any scheme dispatches.
     * Grouped together so a grep for the header name in this file
     * surfaces exactly one definition.
     */
    private const HDR_STRIPE_SIGNATURE   = 'stripe-signature';
    private const HDR_GITHUB_SIGNATURE   = 'x-hub-signature-256';
    private const HDR_SLACK_SIGNATURE    = 'x-slack-signature';
    private const HDR_SLACK_TIMESTAMP    = 'x-slack-request-timestamp';
    private const HDR_GENERIC_SIGNATURE  = 'x-webhook-signature';
    private const HDR_AUTHORIZATION      = 'authorization';

    /**
     * Verify a webhook request against the manifest's endpoint config.
     *
     * @param array{auth: array<string, mixed>} $endpoint Validated webhooks.endpoints[$i].
     * @param string $raw_body                            Raw request body — MUST be the exact
     *                                                    bytes the signature was computed over.
     * @param array<string, string> $headers              Header map. Keys are normalized to
     *                                                    lowercase internally, so callers may
     *                                                    pass title-cased PSR-7-style names
     *                                                    or raw $_SERVER-style entries — they
     *                                                    all resolve the same way.
     * @return true|\WP_Error
     */
    public static function verify(array $endpoint, string $raw_body, array $headers, string $app_id): true|\WP_Error {
        // Defensive lowercase normalization: a caller passing
        // ['Stripe-Signature' => '...'] should not silently degrade to
        // webhook_auth_failed because the scheme dispatchers look up
        // the lowercase key. Single array_change_key_case at the top
        // keeps every downstream branch ignorant of header case.
        $headers = array_change_key_case($headers, CASE_LOWER);
        $auth    = $endpoint['auth'] ?? [];
        $alias   = $auth['secret_alias'] ?? '';
        $secret = is_string($alias) && $alias !== '' ? Secret_Vault::get($app_id, $alias) : null;
        if ($secret === null) {
            return new \WP_Error(
                'webhook_secret_not_set',
                __('Webhook secret is not configured. Set it in the Secrets tab.', 'designsetgo-apps'),
            );
        }
        return match ($auth['type'] ?? null) {
            'hmac-sha256' => self::verify_hmac($auth, $raw_body, $headers, $secret),
            'bearer'      => self::verify_bearer($headers, $secret),
            default       => self::auth_failed(),
        };
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_hmac(array $auth, string $raw_body, array $headers, string $secret): true|\WP_Error {
        return match ($auth['scheme'] ?? null) {
            'stripe'  => self::verify_hmac_stripe($raw_body, $headers, $secret),
            'github'  => self::verify_hmac_github($raw_body, $headers, $secret),
            'slack'   => self::verify_hmac_slack($raw_body, $headers, $secret),
            'generic' => self::verify_hmac_generic($raw_body, $headers, $secret),
            default   => self::auth_failed(),
        };
    }

    /**
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_hmac_stripe(string $body, array $headers, string $secret): true|\WP_Error {
        $sig_header = $headers[self::HDR_STRIPE_SIGNATURE] ?? '';
        // Stripe sends `t=<ts>,v1=<sig>` (other version fields may also
        // appear, e.g. v0; we only honor v1, the current scheme).
        // Allow optional whitespace around commas in case an
        // intermediate proxy pretty-prints the header. Stripe's own
        // SDK is forgiving about this — match its posture.
        if (!preg_match('/(?:^|,)\s*t=(\d+)\s*(?:,|$)/', $sig_header, $tm)
            || !preg_match('/(?:^|,)\s*v1=([a-f0-9]+)\s*(?:,|$)/i', $sig_header, $sm)
        ) {
            return self::auth_failed();
        }
        $ts = (int) $tm[1];
        if (abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return self::auth_failed();
        }
        $expected = hash_hmac('sha256', "{$ts}.{$body}", $secret);
        return hash_equals($expected, $sm[1]) ? true : self::auth_failed();
    }

    /**
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_hmac_github(string $body, array $headers, string $secret): true|\WP_Error {
        $sig_header = $headers[self::HDR_GITHUB_SIGNATURE] ?? '';
        // GitHub always sends the `sha256=` prefix. Rejecting bare hex
        // prevents downgrade attacks where a caller strips the algorithm
        // identifier to confuse signature parsers.
        if (!str_starts_with($sig_header, 'sha256=')) {
            return self::auth_failed();
        }
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $sig_header) ? true : self::auth_failed();
    }

    /**
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_hmac_slack(string $body, array $headers, string $secret): true|\WP_Error {
        $ts_header = $headers[self::HDR_SLACK_TIMESTAMP] ?? '';
        if ($ts_header === '' || !ctype_digit($ts_header)) {
            return self::auth_failed();
        }
        $ts = (int) $ts_header;
        if (abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return self::auth_failed();
        }
        $sig_header = $headers[self::HDR_SLACK_SIGNATURE] ?? '';
        // Mirror GitHub's downgrade guard: a bare hex digest without the
        // `v0=` version prefix must be rejected. If Slack ever ships a
        // `v1=` scheme alongside v0, that would otherwise be silently
        // accepted by length coincidence.
        if (!str_starts_with($sig_header, 'v0=')) {
            return self::auth_failed();
        }
        $expected = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);
        return hash_equals($expected, $sig_header) ? true : self::auth_failed();
    }

    /**
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_hmac_generic(string $body, array $headers, string $secret): true|\WP_Error {
        $sig_header = $headers[self::HDR_GENERIC_SIGNATURE] ?? '';
        $expected   = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $sig_header) ? true : self::auth_failed();
    }

    /**
     * @param array<string, string> $headers
     * @return true|\WP_Error
     */
    private static function verify_bearer(array $headers, string $secret): true|\WP_Error {
        $value = $headers[self::HDR_AUTHORIZATION] ?? '';
        // RFC 7235 §2.1: auth-scheme tokens are case-insensitive.
        // Producers may legitimately send `bearer`, `Bearer`, or `BEARER`
        // (and proxies sometimes uppercase the whole header value).
        // Match on the scheme name case-insensitively while leaving
        // the token bytes case-sensitive — `hash_equals` on the
        // post-prefix slice keeps timing constant.
        if (stripos($value, 'Bearer ') !== 0) {
            return self::auth_failed();
        }
        $token = substr($value, 7);
        return hash_equals($secret, $token) ? true : self::auth_failed();
    }

    private static function auth_failed(): \WP_Error {
        return new \WP_Error(
            'webhook_auth_failed',
            __('Webhook authentication failed.', 'designsetgo-apps'),
        );
    }
}
