<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Http_Proxy_Bridge;
use DSGo_Apps\Http_Proxy_Log;
use DSGo_Apps\Manifest;
use DSGo_Apps\Permission;
use DSGo_Apps\Secret_Vault;
use WP_UnitTestCase;

/**
 * Tests for Http_Proxy_Bridge — the 13-step enforcement pipeline behind
 * `dsgo.http.fetch`. The bridge is pure logic; tests inject a transport
 * factory and (for SSRF) a DNS resolver instead of touching the network.
 *
 * Return shape contract:
 *   success — { ok: true, status: int, headers: array, body: mixed }
 *   failure — { error_code: string, message: string, retry_after_seconds?: int }
 *
 * Every test calls `Http_Proxy_Bridge::reset_for_tests()` in tear_down so
 * static factory/resolver state doesn't bleed across tests.
 */
final class HttpProxyBridgeTest extends WP_UnitTestCase {

    public function tear_down(): void {
        Http_Proxy_Bridge::reset_for_tests();
        remove_all_filters('dsgo_apps_http_rate_per_minute');
        remove_all_filters('dsgo_apps_http_response_max_bytes');
        remove_all_filters('dsgo_apps_http_request_max_bytes');
        // Clear secrets so cross-test secret state doesn't leak.
        Secret_Vault::delete_all('proxy-test');
        // Reset the audit log so log-row tests stay deterministic.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_http_log");
        parent::tear_down();
    }

    public function set_up(): void {
        parent::set_up();
        // The audit log table is created on activate(); ensure it exists
        // for the test DB (which may not have been activate()d on this
        // process boot).
        Http_Proxy_Log::create_table();
    }

    // ----- Step 1: permission -----

    public function test_returns_error_when_no_http_permission(): void {
        $m = $this->manifest([]);   // no permissions.http
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        $this->assertSame('http_permission_denied', $result->error_code);
    }

    // ----- Step 3: URL parse + scheme -----

    public function test_rejects_non_https_url(): void {
        $m = $this->manifest(['api.stripe.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'http://api.stripe.com/v1/charges', []);
        $this->assertSame('http_invalid_url', $result->error_code);
    }

    public function test_rejects_malformed_url(): void {
        $m = $this->manifest(['api.stripe.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'not a url', []);
        $this->assertSame('http_invalid_url', $result->error_code);
    }

    // ----- Step 2: method validation -----

    public function test_rejects_unsupported_http_method(): void {
        $m = $this->manifest(['api.stripe.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'method' => 'CONNECT',
        ]);
        $this->assertSame('http_method_not_allowed', $result->error_code);
    }

    // ----- Step 4: allowlist -----

    public function test_rejects_host_not_in_allowlist(): void {
        $m = $this->manifest(['api.stripe.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.notion.com/v1/pages', []);
        $this->assertSame('http_host_not_allowed', $result->error_code);
    }

    public function test_wildcard_host_matches_single_label_subdomain(): void {
        $m = $this->manifest(['*.notion.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.notion.com/v1/pages', []);
        $this->assertSame(200, $result->status);
    }

    public function test_wildcard_host_does_not_match_multi_label_subdomain(): void {
        // *.notion.com matches `api.notion.com` but NOT `a.b.notion.com`.
        $m = $this->manifest(['*.notion.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://a.b.notion.com/v1/pages', []);
        $this->assertSame('http_host_not_allowed', $result->error_code);
    }

    public function test_wildcard_host_does_not_match_bare_apex(): void {
        // *.notion.com requires at least one subdomain label.
        $m = $this->manifest(['*.notion.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://notion.com/v1/pages', []);
        $this->assertSame('http_host_not_allowed', $result->error_code);
    }

    public function test_allowlist_is_case_insensitive(): void {
        // Hostnames are case-insensitive per RFC; the manifest validator
        // already lowercases on input, but the bridge must still match
        // requests with mixed-case host components.
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://API.Stripe.COM/v1/charges', []);
        $this->assertSame(200, $result->status);
    }

    // ----- Step 5: SSRF guard -----

    public function test_ssrf_guard_rejects_dns_resolving_to_private_range(): void {
        $m = $this->manifest(['internal.example']);
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['10.0.0.5']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://internal.example/secret', []);
        $this->assertSame('http_ssrf_blocked', $result->error_code);
    }

    public function test_ssrf_guard_rejects_loopback_resolution(): void {
        $m = $this->manifest(['internal.example']);
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['127.0.0.1']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://internal.example/', []);
        $this->assertSame('http_ssrf_blocked', $result->error_code);
    }

    public function test_ssrf_guard_rejects_ipv6_loopback(): void {
        $m = $this->manifest(['internal.example']);
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['::1']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://internal.example/', []);
        $this->assertSame('http_ssrf_blocked', $result->error_code);
    }

    // ----- Step 6: header sanitize -----

    public function test_strips_cookie_header_outbound(): void {
        $m = $this->manifest(['api.stripe.com']);
        $captured = ['headers' => null];
        Http_Proxy_Bridge::set_transport_factory_for_tests(function ($url, $args) use (&$captured) {
            $captured['headers'] = $args['headers'] ?? [];
            return $this->stub_response(200, '{}', ['content-type' => 'application/json']);
        });
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Cookie' => 'session=abc', 'X-Allowed' => 'ok'],
        ]);
        $this->assertArrayNotHasKey('Cookie', $captured['headers']);
        $this->assertArrayHasKey('X-Allowed', $captured['headers']);
    }

    public function test_rejects_invalid_header_name(): void {
        $m = $this->manifest(['api.stripe.com']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Bad Name With Spaces' => 'oops'],
        ]);
        $this->assertSame('http_invalid_header', $result->error_code);
    }

    // ----- Step 7: body size -----

    public function test_rejects_oversized_request_body(): void {
        $m = $this->manifest(['api.stripe.com']);
        add_filter('dsgo_apps_http_request_max_bytes', fn () => 10);
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'method' => 'POST',
            'body'   => str_repeat('x', 500),
        ]);
        $this->assertSame('http_request_too_large', $result->error_code);
    }

    // ----- Step 8: secret substitution -----

    public function test_substitutes_secret_in_outbound_header(): void {
        $m = $this->manifest(
            ['api.stripe.com'],
            secrets: [['alias' => 'SK', 'description' => 'Stripe secret key for charges API.']],
        );
        Secret_Vault::set($m->id, 'SK', 'sk_test_xyz');
        $captured = ['headers' => null];
        Http_Proxy_Bridge::set_transport_factory_for_tests(function ($url, $args) use (&$captured) {
            $captured['headers'] = $args['headers'] ?? [];
            return $this->stub_response(200, '{"id":"ch_1"}', ['content-type' => 'application/json']);
        });
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Authorization' => 'Bearer {{SK}}'],
        ]);
        $this->assertSame(200, $result->status);
        $this->assertSame('Bearer sk_test_xyz', $captured['headers']['Authorization']);
    }

    public function test_response_does_not_echo_substituted_secret_back_to_caller(): void {
        // Whatever the upstream returns, the bridge's reply headers should
        // not include the Authorization request header — that's the iframe's
        // attack surface (the app composed `Bearer {{SK}}` but the resolved
        // value must never become visible to the app's JS).
        $m = $this->manifest(
            ['api.stripe.com'],
            secrets: [['alias' => 'SK', 'description' => 'Stripe secret key for charges API.']],
        );
        Secret_Vault::set($m->id, 'SK', 'sk_test_xyz');
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{"id":"ch_1"}', ['content-type' => 'application/json']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Authorization' => 'Bearer {{SK}}'],
        ]);
        $this->assertArrayNotHasKey('authorization', array_change_key_case($result->headers, CASE_LOWER));
    }

    public function test_rejects_unknown_secret_alias(): void {
        $m = $this->manifest(['api.stripe.com']);   // no secrets[]
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Authorization' => 'Bearer {{SK}}'],
        ]);
        $this->assertSame('http_unknown_secret', $result->error_code);
    }

    public function test_rejects_unset_secret(): void {
        // Alias is declared in manifest but the admin hasn't entered a value yet.
        $m = $this->manifest(
            ['api.stripe.com'],
            secrets: [['alias' => 'SK', 'description' => 'Stripe secret key for charges API.']],
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => ['Authorization' => 'Bearer {{SK}}'],
        ]);
        $this->assertSame('http_secret_not_set', $result->error_code);
    }

    // ----- Step 9: rate limit -----

    public function test_rate_limit_blocks_after_threshold(): void {
        $m = $this->manifest(['api.stripe.com']);
        add_filter('dsgo_apps_http_rate_per_minute', fn () => 2);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        $third = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        $this->assertSame('http_rate_limited', $third->error_code);
        $this->assertGreaterThan(0, $third->retry_after_seconds);
    }

    // ----- Step 11: response cap -----

    public function test_response_too_large_fails_cleanly(): void {
        $m = $this->manifest(['api.stripe.com']);
        add_filter('dsgo_apps_http_response_max_bytes', fn () => 10);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, str_repeat('x', 100), []),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/', []);
        $this->assertSame('http_response_too_large', $result->error_code);
    }

    public function test_strips_set_cookie_from_response_headers(): void {
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', [
                'content-type' => 'application/json',
                'set-cookie'   => 'sid=abc; HttpOnly',
            ]),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/', []);
        $headers_lc = array_change_key_case($result->headers, CASE_LOWER);
        $this->assertArrayNotHasKey('set-cookie', $headers_lc);
    }

    // ----- Step 12: JSON auto-parse -----

    public function test_json_content_type_auto_parses_body(): void {
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{"amount":100}', ['content-type' => 'application/json']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        $this->assertIsArray($result->body);
        $this->assertSame(100, $result->body['amount']);
    }

    public function test_non_json_content_type_keeps_raw_string_body(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, 'plain text response', ['content-type' => 'text/plain']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        $this->assertSame('plain text response', $result->body);
    }

    public function test_json_parse_failure_keeps_raw_and_marks_header(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{not really json', ['content-type' => 'application/json']),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        $this->assertSame('{not really json', $result->body);
        $headers_lc = array_change_key_case($result->headers, CASE_LOWER);
        $this->assertSame('1', $headers_lc['x-dsgo-json-parse-error']);
    }

    // ----- Step 10: transport failure surface -----

    public function test_wp_error_with_timeout_maps_to_http_timeout(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => new \WP_Error('http_request_failed', 'Operation timed out'),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        $this->assertSame('http_timeout', $result->error_code);
    }

    public function test_wp_error_without_timeout_maps_to_network_error(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => new \WP_Error('http_request_failed', 'Could not resolve host'),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        $this->assertSame('http_network_error', $result->error_code);
    }

    // ----- Step 13: audit log -----

    public function test_logs_row_on_successful_fetch(): void {
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{"id":"ok"}', ['content-type' => 'application/json']),
        );
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges?secret=should_not_log', []);

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1", ARRAY_A);
        $this->assertNotNull($row);
        $this->assertSame('proxy-test',     $row['app_id']);
        $this->assertSame('api.stripe.com', $row['host']);
        $this->assertSame('GET',            $row['method']);
        $this->assertSame(200,              (int) $row['status']);
        // Query string is stripped (may contain secrets) — path is path-only.
        $this->assertSame('/v1/charges',    $row['path']);
    }

    public function test_logs_row_with_status_zero_on_transport_failure(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => new \WP_Error('http_request_failed', 'Could not resolve host'),
        );
        Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $status = $wpdb->get_var("SELECT status FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1");
        $this->assertSame(0, (int) $status);
    }

    // ----- review-driven security regressions -----

    public function test_permission_denied_writes_log_row_with_host_info(): void {
        // Apps that pound the proxy without permissions.http should not be
        // invisible in the audit table. Regression guard for the review
        // finding that step 1 short-circuited silently.
        $m = $this->manifest([]);
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}dsgo_apps_http_log LIMIT 1", ARRAY_A);
        $this->assertNotNull($row, 'permission_denied must still write a row');
        $this->assertSame('api.stripe.com', $row['host']);
        $this->assertSame(0,                (int) $row['status']);
    }

    public function test_does_not_follow_redirects(): void {
        // Critical SSRF defense: an allowlisted host can return
        // `Location: http://10.0.0.1/` to coax the proxy into fetching a
        // private target. The bridge must pass `redirection => 0` to the
        // transport and surface the 30x verbatim instead of following.
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        $captured = ['args' => null];
        Http_Proxy_Bridge::set_transport_factory_for_tests(function ($url, $args) use (&$captured) {
            $captured['args'] = $args;
            return $this->stub_response(302, '', ['location' => 'http://10.0.0.1/admin']);
        });
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/redirect-me', []);
        $this->assertSame(0, (int) $captured['args']['redirection'],
            'bridge must pass redirection=0 so the transport does not follow into the internal network');
        $this->assertSame(302, $result->status);
    }

    public function test_curl_pin_filter_cleaned_up_after_fetch(): void {
        // When the bridge falls back to the real wp_remote_request transport
        // it adds an `http_api_curl` action to pin CURLOPT_RESOLVE to the
        // SSRF-validated IP. That add must be paired with a remove in a
        // try/finally — otherwise the filter accumulates across requests
        // and pins later requests to stale IPs.
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        // Short-circuit the actual HTTP via pre_http_request so we don't
        // hit the network. pre_http_request fires inside wp_remote_request,
        // AFTER the bridge has called add_action('http_api_curl', ...),
        // so the add+remove pairing is exercised even though no cURL
        // handle ever opens.
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{}',
            'headers'  => ['content-type' => 'application/json'],
        ]);
        try {
            Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        } finally {
            remove_all_filters('pre_http_request');
        }
        global $wp_filter;
        $leaked = isset($wp_filter['http_api_curl'])
            && !empty($wp_filter['http_api_curl']->callbacks[10] ?? []);
        $this->assertFalse($leaked, 'CURLOPT_RESOLVE pin callback must be removed after fetch returns');
    }

    // ----- Phase 9: full SSRF resolution matrix -----

    /**
     * @dataProvider ssrf_blocked_address_provider
     */
    public function test_ssrf_guard_rejects_each_blocked_address(string $label, string $ip): void {
        // Every entry here represents a class of internal-network address an
        // attacker-controlled DNS could return for an allowlisted hostname.
        // The guard MUST reject each one with http_ssrf_blocked, regardless
        // of v4 vs v6 family or the specific reserved range. AWS metadata
        // (169.254.169.254) is the canonical SSRF target — pin it explicitly.
        $m = $this->manifest(['internal.example']);
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => [$ip]);
        $result = Http_Proxy_Bridge::fetch($m, 'https://internal.example/', []);
        $this->assertSame('http_ssrf_blocked', $result->error_code,
            "SSRF guard must reject $label ($ip)");
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function ssrf_blocked_address_provider(): array {
        return [
            'loopback v4'        => ['loopback v4',            '127.0.0.1'],
            'loopback v6'        => ['loopback v6',            '::1'],
            'aws metadata'       => ['aws metadata (link-local)', '169.254.169.254'],
            'rfc1918 10/8'       => ['rfc1918 10/8',           '10.0.0.1'],
            'rfc1918 172.16/12'  => ['rfc1918 172.16/12',      '172.16.0.1'],
            'rfc1918 192.168/16' => ['rfc1918 192.168/16',     '192.168.1.1'],
            'cgnat 100.64/10'    => ['cgnat 100.64/10',        '100.64.5.5'],
            'multicast v4'       => ['multicast v4',           '224.0.0.1'],
            'reserved 240/4'     => ['reserved 240/4',         '240.0.0.1'],
            'unique-local v6'    => ['unique-local v6',        'fc00::1'],
            'link-local v6'      => ['link-local v6',          'fe80::1'],
            'multicast v6'       => ['multicast v6',           'ff02::1'],
        ];
    }

    public function test_ssrf_guard_rejects_dns_resolving_to_aws_metadata(): void {
        // Mirror the matrix entry as a named test so a regression here is
        // easy to spot in the report — AWS metadata is the canonical SSRF
        // target and the test name surfaces it.
        $m = $this->manifest(['internal.example']);
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['169.254.169.254']);
        $result = Http_Proxy_Bridge::fetch($m, 'https://internal.example/latest/meta-data/', []);
        $this->assertSame('http_ssrf_blocked', $result->error_code);
    }

    // ----- Phase 9: outbound header strip matrix -----

    /**
     * @dataProvider blocked_outbound_header_provider
     */
    public function test_blocked_outbound_header_is_stripped(string $header_name): void {
        $m = $this->manifest(['api.stripe.com']);
        $captured = ['headers' => null];
        Http_Proxy_Bridge::set_transport_factory_for_tests(function ($url, $args) use (&$captured) {
            $captured['headers'] = $args['headers'] ?? [];
            return $this->stub_response(200, '{}', ['content-type' => 'application/json']);
        });
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', [
            'headers' => [$header_name => 'value', 'X-Allowed' => 'ok'],
        ]);
        // Match case-insensitively — the bridge preserves the caller's case
        // for headers it forwards, but blocked names use case-insensitive
        // matching by design.
        $sent_lc = array_change_key_case($captured['headers'], CASE_LOWER);
        $this->assertArrayNotHasKey(strtolower($header_name), $sent_lc,
            "blocked header \"$header_name\" must not be forwarded outbound");
        $this->assertArrayHasKey('X-Allowed', $captured['headers'],
            'unblocked headers must still pass through alongside the strip');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function blocked_outbound_header_provider(): array {
        return [
            'Cookie'             => ['Cookie'],
            'Set-Cookie'         => ['Set-Cookie'],
            'Set-Cookie2'        => ['Set-Cookie2'],
            'Host'               => ['Host'],
            'Connection'         => ['Connection'],
            'Keep-Alive'         => ['Keep-Alive'],
            'Upgrade'            => ['Upgrade'],
            'Transfer-Encoding'  => ['Transfer-Encoding'],
            'Content-Length'     => ['Content-Length'],
            'X-Forwarded-For'    => ['X-Forwarded-For'],
            'X-Forwarded-Proto'  => ['X-Forwarded-Proto'],
            'Proxy-Authorization'=> ['Proxy-Authorization'],
        ];
    }

    // ----- Phase 9: filter overrides for the four budget knobs -----

    public function test_request_max_bytes_filter_relaxes_the_default(): void {
        // Default cap is 1 MB; bump it via filter to confirm callers can
        // raise the ceiling for legitimate large-payload upstreams.
        $m = $this->manifest(['api.stripe.com']);
        $this->bypass_ssrf();
        add_filter('dsgo_apps_http_request_max_bytes', fn () => 5_000_000);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        // 2 MB body — would reject under the default, must pass with the filter.
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/', [
            'method' => 'POST',
            'body'   => str_repeat('x', 2_000_000),
        ]);
        $this->assertSame(200, $result->status);
    }

    public function test_response_max_bytes_filter_tightens_the_default(): void {
        $m = $this->manifest(['api.example.com']);
        $this->bypass_ssrf();
        add_filter('dsgo_apps_http_response_max_bytes', fn () => 50);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, str_repeat('x', 200), []),
        );
        $result = Http_Proxy_Bridge::fetch($m, 'https://api.example.com/', []);
        $this->assertSame('http_response_too_large', $result->error_code);
    }

    public function test_rate_per_minute_filter_zero_disables_limit(): void {
        // Documented behavior in rate_limit_check(): a filter value <= 0
        // disables the rate limiter entirely. Tested separately from the
        // "blocks after threshold" test (which uses a positive cap).
        $m = $this->manifest(['api.stripe.com']);
        $this->bypass_ssrf();
        add_filter('dsgo_apps_http_rate_per_minute', fn () => 0);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        // 100 calls in a tight loop — none should rate-limit with cap=0.
        for ($i = 0; $i < 100; $i++) {
            $result = Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/', []);
            $this->assertSame(200, $result->status, "call #$i unexpectedly rejected");
        }
    }

    public function test_log_retention_filter_drives_purge_cutoff(): void {
        // The retention-days filter lives on Http_Proxy_Log::purge_expired,
        // not the bridge itself, but the bridge surface is what causes rows
        // to land in the table. Drive a fetch, fake an old created_at, set
        // a 1-day retention, run purge — row should be gone.
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        global $wpdb;
        $table = $wpdb->prefix . 'dsgo_apps_http_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET created_at = %s WHERE app_id = %s",
            gmdate('Y-m-d H:i:s', time() - (5 * DAY_IN_SECONDS)),
            'proxy-test',
        ));

        add_filter('dsgo_apps_http_log_retention_days', fn () => 1);
        Http_Proxy_Log::purge_expired();
        remove_all_filters('dsgo_apps_http_log_retention_days');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE app_id = 'proxy-test'");
        $this->assertSame(0, $count, 'retention filter should drive the cutoff Http_Proxy_Log purges against');
    }

    // ----- Phase 9: audit-log row-count invariants -----

    public function test_each_fetch_writes_exactly_one_log_row(): void {
        // A long-running app firing through the proxy must produce one
        // audit row per attempt — neither swallowed (which would hide
        // abuse) nor doubled (which would skew rate-limit visibility).
        $m = $this->manifest(['api.stripe.com']);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        for ($i = 0; $i < 5; $i++) {
            Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dsgo_apps_http_log");
        $this->assertSame(5, $count);
    }

    public function test_mixed_success_and_failure_each_logs_one_row(): void {
        // A success, a SSRF-block, and a rate-limit hit should each produce
        // exactly one row — the rate-limit path is the easiest to double-log
        // because it runs late in the pipeline.
        $m = $this->manifest(['api.stripe.com']);
        add_filter('dsgo_apps_http_rate_per_minute', fn () => 1);
        Http_Proxy_Bridge::set_transport_factory_for_tests(
            fn () => $this->stub_response(200, '{}', ['content-type' => 'application/json']),
        );
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);   // success
        Http_Proxy_Bridge::fetch($m, 'https://api.stripe.com/v1/charges', []);   // rate-limited

        // Disallowed host — different host, not consumed by rate-limit yet.
        Http_Proxy_Bridge::fetch($m, 'https://api.notion.com/v1/pages', []);     // host_not_allowed

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dsgo_apps_http_log");
        $this->assertSame(3, $rows);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $statuses = $wpdb->get_col("SELECT status FROM {$wpdb->prefix}dsgo_apps_http_log ORDER BY id ASC");
        $this->assertSame(['200', '0', '0'], $statuses);
    }

    // ===== helpers =====

    /**
     * Construct a Manifest with the given http allowlist and (optional)
     * secrets. Bypasses validate() so tests don't have to provide a full
     * manifest payload — only the fields the bridge actually reads.
     *
     * @param string[]                                    $http_allowlist
     * @param array<int, array{alias:string, description:string}> $secrets
     */
    private function manifest(array $http_allowlist, array $secrets = []): Manifest {
        return Manifest::from_array_unchecked([
            'manifest_version' => 1,
            'id'               => 'proxy-test',
            'name'             => 'Proxy Test',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => [
                'read'  => [],
                'write' => [],
                'http'  => $http_allowlist,
            ],
            'secrets'          => $secrets,
            'runtime'          => ['sandbox' => 'strict'],
        ]);
    }

    /**
     * Tests that drive the transport but use a host that does not resolve
     * in the test environment (`api.example.com`, etc.) call this to
     * short-circuit the SSRF guard with a benign public IP. SSRF-specific
     * tests still override DNS to a private/loopback address.
     */
    private function bypass_ssrf(): void {
        Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['93.184.215.14']);
    }

    /**
     * Build a stubbed wp_remote_request-style response array.
     *
     * @param array<string, string> $headers
     */
    private function stub_response(int $status, string $body, array $headers): array {
        return [
            'response' => ['code' => $status, 'message' => 'OK'],
            'body'     => $body,
            'headers'  => $headers,
        ];
    }
}
