<?php
/**
 * Enforcement pipeline for `dsgo.http.fetch` — the per-app outbound HTTP
 * proxy that lets sandboxed bundles call third-party APIs (Stripe, Notion,
 * Airtable, GitHub, etc.) without the iframe ever seeing a credential.
 *
 * The bridge is pure logic — no REST, no admin, no rendering. The REST
 * layer (Phase 5) calls Http_Proxy_Bridge::fetch() and maps the result
 * to status codes; the admin "Secrets" tab (Phase 7) does not touch this
 * file. Tests inject a transport factory via set_transport_factory_for_tests
 * to keep the suite offline and deterministic.
 *
 * 13-step pipeline (each step short-circuits to ::error() on rejection):
 *   1.  URL parse    — must be a syntactically valid https:// URL.
 *   2.  Permission   — manifest's permissions.http must be non-empty.
 *   3.  Method       — uppercase, must be GET/POST/PUT/PATCH/DELETE/HEAD.
 *   4.  Allowlist    — host matches an exact entry or *.subdomain wildcard.
 *   5.  SSRF guard   — DNS resolution must not return private / loopback IPs.
 *   6.  Headers      — sanitize: strip Cookie/Set-Cookie/Host/Connection/etc.
 *   7.  Body size    — request body capped (filter: ..._request_max_bytes).
 *   8.  Secrets      — substitute {{ALIAS}} from Secret_Vault in headers + body.
 *   9.  Rate limit   — per-app per-minute bucket (filter: ..._rate_per_minute).
 *   10. Issue        — wp_remote_request with `redirection => 0` (the bridge
 *                      does NOT follow 30x, so a malicious allowlisted host
 *                      can't return `Location: http://10.0.0.1/` and get the
 *                      proxy to fetch private targets). On the cURL transport
 *                      the host is pinned to the SSRF-validated IP via
 *                      CURLOPT_RESOLVE to defeat DNS rebinding between the
 *                      guard and the actual fetch.
 *   11. Response cap — body size limit + strip Set-Cookie from reply headers.
 *   12. JSON parse   — auto-parse on application/(...+)?json content-type.
 *   13. Log          — Http_Proxy_Log::log() on every outcome past URL parse
 *                      (status=0 on every non-success path).
 *
 * Return shape:
 *   success ⇒ object{ok:true, status:int, headers:array, body:mixed}
 *   failure ⇒ object{error_code:string, message:string, retry_after_seconds?:int}
 *
 * The proxy refuses to run unless the active transport can pin the validated
 * IP with CURLOPT_RESOLVE. That keeps DNS rebinding from turning a valid
 * allowlisted request into a follow-up request to a private address.
 *   - default_resolve() does not enforce a DNS timeout — a slow authoritative
 *     server can stall the request beyond timeout_ms. Tracked for v1.1.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Http_Proxy_Bridge {

    /** Default per-app per-minute call cap; filter `dsgo_apps_http_rate_per_minute`. */
    private const DEFAULT_RATE_PER_MINUTE = 60;

    /**
     * Rate-limit bucket transient TTL (seconds). Set to 90s — longer than
     * the 60s minute bucket — so a request landing at sec 59 doesn't reset
     * the counter for the next minute.
     */
    private const RATE_LIMIT_TTL_SECONDS = 90;

    /** Default request timeout (ms) when the caller omits `timeout_ms`. */
    private const DEFAULT_TIMEOUT_MS = 10000;

    /** Lower clamp bound (ms) for a caller-supplied `timeout_ms`. */
    private const MIN_TIMEOUT_MS = 1000;

    /** Upper clamp bound (ms) for a caller-supplied `timeout_ms`. */
    private const MAX_TIMEOUT_MS = 30000;

    /** Default request body cap (bytes); filter `dsgo_apps_http_request_max_bytes`. */
    private const DEFAULT_REQUEST_MAX_BYTES = 1_048_576;   // 1 MB

    /** Default response body cap (bytes); filter `dsgo_apps_http_response_max_bytes`. */
    private const DEFAULT_RESPONSE_MAX_BYTES = 1_048_576;  // 1 MB

    /** Methods the proxy will pass through. CONNECT/OPTIONS/TRACE are blocked. */
    private const ALLOWED_METHODS = ['GET','POST','PUT','PATCH','DELETE','HEAD'];

    /** Outbound headers that callers must not be able to set. */
    private const BLOCKED_HEADERS = [
        'cookie','set-cookie','set-cookie2','host','connection',
        'keep-alive','upgrade','transfer-encoding','content-length',
    ];

    /** @var (\Closure(string $url, array $args): (array|\WP_Error))|null */
    private static ?\Closure $transport_factory = null;

    /** @var (\Closure(string $host): string[])|null */
    private static ?\Closure $dns_resolver = null;

    public static function set_transport_factory_for_tests(?\Closure $f): void {
        self::$transport_factory = $f;
    }

    public static function set_dns_resolver_for_tests(?\Closure $f): void {
        self::$dns_resolver = $f;
    }

    public static function reset_for_tests(): void {
        self::$transport_factory = null;
        self::$dns_resolver      = null;
    }

    /**
     * Run the 13-step pipeline against a single request.
     *
     * Thin orchestrator: each cohesive group of pipeline steps lives in its
     * own private method that returns either its output value or a
     * BridgeResult error (short-circuit). fetch() threads those together and
     * serializes the final BridgeResult via to_http_object() — the wire shape
     * (object{ok,status,headers,body} on success; object{error_code,message
     * [,retry_after_seconds]} on failure) is unchanged.
     *
     * @param array{
     *   method?:string,
     *   headers?:array<string,string>,
     *   body?:string|null,
     *   timeout_ms?:int,
     * } $init
     */
    public static function fetch(Manifest $manifest, string $url, array $init): object {
        $t_start = hrtime(true);

        // === Step 1: URL parse + scheme ===
        $parsed = self::parse_request_url($url);
        if ($parsed instanceof BridgeResult) {
            return $parsed->to_http_object();
        }
        $host = $parsed['host'];
        $path = $parsed['path'];

        // Log context shared by every step 2-13 audit-log write. Built once
        // here so the step helpers don't each rebuild the same five fields.
        $method = strtoupper((string) ($init['method'] ?? 'GET'));
        $log_ctx = [
            'app_id'  => $manifest->id,
            'host'    => $host,
            'method'  => $method,
            'path'    => $path,
            't_start' => $t_start,
        ];

        // === Steps 2-4: permission, method, allowlist ===
        $gate_err = self::check_request_gates($manifest, $method, $log_ctx);
        if ($gate_err instanceof BridgeResult) {
            return $gate_err->to_http_object();
        }

        // === Steps 5-9: SSRF guard, header sanitize, body cap, secret
        // substitution, rate limit. On success returns the prepared request
        // state (clean headers, substituted body, validated IP list). ===
        $prepared = self::prepare_request($manifest, $init, $log_ctx);
        if ($prepared instanceof BridgeResult) {
            return $prepared->to_http_object();
        }

        // === Step 10: issue the request ===
        $issued = self::issue_request($url, $parsed['parsed'], $prepared, $log_ctx);
        if ($issued instanceof BridgeResult) {
            return $issued->to_http_object();
        }

        // === Steps 11-13: response cap + header strip, JSON auto-parse,
        // success audit-log row. ===
        return self::process_response($issued, $prepared['req_bytes'], $log_ctx)->to_http_object();
    }

    /**
     * Step 1: parse + validate the request URL. Done first so every
     * subsequent rejection (including permission denial) can write an audit
     * log row tagged with the host the app tried to reach.
     *
     * Returns `['host'=>string, 'path'=>string, 'parsed'=>array]` on success,
     * or a BridgeResult error. A malformed URL is the ONLY rejection path
     * that does not write a log row — there's no parseable host to tag it.
     *
     * @return array{host:string,path:string,parsed:array<string,mixed>}|BridgeResult
     */
    private static function parse_request_url(string $url): array|BridgeResult {
        $parsed = wp_parse_url($url);
        if (!is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https' || empty($parsed['host'])) {
            return BridgeResult::error('http_invalid_url',
                'url must be a syntactically valid https:// URL');
        }
        $host = strtolower((string) $parsed['host']);
        $path = (string) ($parsed['path'] ?? '/');
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $path .= '?' . $parsed['query'];
        }
        return ['host' => $host, 'path' => $path, 'parsed' => $parsed];
    }

    /**
     * Steps 2-4: permission, method validation, host allowlist. Each
     * rejection writes a status=0 audit-log row via log_and_error().
     *
     * Returns null when all three gates pass, or a BridgeResult error.
     *
     * @param array{app_id:string,host:string,method:string,path:string,t_start:int} $log_ctx
     */
    private static function check_request_gates(Manifest $manifest, string $method, array $log_ctx): ?BridgeResult {
        // Step 2: permission.
        if ($manifest->permissions_http === []) {
            return self::log_and_error($log_ctx,
                'http_permission_denied',
                'app manifest has no permissions.http allowlist');
        }

        // Step 3: method validation.
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return self::log_and_error($log_ctx,
                'http_method_not_allowed',
                sprintf('method "%s" is not permitted by the proxy', $method));
        }

        // Step 4: allowlist.
        if (!self::host_is_allowed($log_ctx['host'], $manifest->permissions_http)) {
            return self::log_and_error($log_ctx,
                'http_host_not_allowed',
                sprintf('host "%s" is not in the manifest allowlist', $log_ctx['host']));
        }

        return null;
    }

    /**
     * Steps 5-9: SSRF guard, outbound header sanitize, request body cap,
     * secret substitution, and per-app rate limit. Each rejection writes a
     * status=0 audit-log row.
     *
     * Returns the prepared request state on success:
     *   ['headers'=>array, 'body'=>?string, 'resolved_ips'=>string[],
     *    'req_bytes'=>int, 'timeout_ms'=>int]
     * or a BridgeResult error.
     *
     * @param array<string,mixed> $init
     * @param array{app_id:string,host:string,method:string,path:string,t_start:int} $log_ctx
     * @return array{headers:array<string,string>,body:?string,resolved_ips:string[],req_bytes:int,timeout_ms:int}|BridgeResult
     */
    private static function prepare_request(Manifest $manifest, array $init, array $log_ctx): array|BridgeResult {
        $host = $log_ctx['host'];

        // Step 5: SSRF guard. resolve_and_validate() returns the resolved IP
        // list on success so step 10 can pin one of them via CURLOPT_RESOLVE
        // (defeats DNS rebinding between this check and the actual fetch).
        $ssrf = self::resolve_and_validate($host);
        if (isset($ssrf['error'])) {
            return self::log_and_error($log_ctx,
                'http_ssrf_blocked',
                sprintf('host "%s" resolves to a blocked address (%s)', $host, $ssrf['error']));
        }
        $resolved_ips = $ssrf['ips'];

        // Step 6: header sanitize.
        $raw_headers = is_array($init['headers'] ?? null) ? $init['headers'] : [];
        $clean_headers = [];
        $hdr_err = self::sanitize_headers($raw_headers, $clean_headers);
        if ($hdr_err !== null) {
            return self::log_and_error($log_ctx, 'http_invalid_header', $hdr_err);
        }

        // Step 7: request body size.
        $body = $init['body'] ?? null;
        if ($body !== null && !is_string($body)) {
            // Non-string bodies aren't supported in v1 (no JSON-object init form).
            return self::log_and_error($log_ctx,
                'http_invalid_body', 'body must be a string (already-serialized)');
        }
        $req_bytes = $body === null ? 0 : strlen($body);
        $req_cap   = (int) apply_filters('dsgo_apps_http_request_max_bytes', self::DEFAULT_REQUEST_MAX_BYTES);
        if ($req_bytes > $req_cap) {
            return self::log_and_error($log_ctx,
                'http_request_too_large',
                sprintf('request body is %d bytes (max %d)', $req_bytes, $req_cap));
        }

        // Step 8: secret substitution.
        $subst_err = self::substitute_secrets($manifest, $clean_headers, $body);
        if ($subst_err !== null) {
            return self::log_and_error($log_ctx, $subst_err['code'], $subst_err['message']);
        }

        // Step 9: rate limit.
        $rl = self::rate_limit_check($manifest->id);
        if ($rl !== null) {
            return self::log_and_error($log_ctx,
                'http_rate_limited',
                'per-app HTTP rate limit exceeded; retry after the window resets',
                ['retry_after_seconds' => $rl['retry_after_seconds']]);
        }

        // Caller-supplied timeout, clamped to the allowed window. Captured
        // here (the only place $init is in scope) and threaded to step 10.
        $timeout_ms = (int) ($init['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS);
        $timeout_ms = max(self::MIN_TIMEOUT_MS, min(self::MAX_TIMEOUT_MS, $timeout_ms));

        return [
            'headers'      => $clean_headers,
            'body'         => $body,
            'resolved_ips' => $resolved_ips,
            'req_bytes'    => $req_bytes,
            'timeout_ms'   => $timeout_ms,
        ];
    }

    /**
     * Step 10: issue the outbound request through the active transport.
     *
     * Returns the raw wp_remote_request-shaped response array on success, or
     * a BridgeResult error (transport unsupported / WP_Error / unexpected
     * response shape). The CURLOPT_RESOLVE DNS-pinning and `redirection => 0`
     * SSRF defenses are preserved exactly — relocated, not altered.
     *
     * @param array<string,mixed> $parsed   Output of wp_parse_url().
     * @param array{headers:array<string,string>,body:?string,resolved_ips:string[],req_bytes:int,timeout_ms:int} $prepared
     * @param array{app_id:string,host:string,method:string,path:string,t_start:int} $log_ctx
     * @return array<string,mixed>|BridgeResult
     */
    private static function issue_request(string $url, array $parsed, array $prepared, array $log_ctx): array|BridgeResult {
        $body      = $prepared['body'];
        $req_bytes = $prepared['req_bytes'];

        $args = [
            'method'      => $log_ctx['method'],
            'headers'     => $prepared['headers'],
            'timeout'     => $prepared['timeout_ms'] / 1000,
            // redirection => 0 is load-bearing: an allowlisted host could
            // otherwise return `Location: http://10.0.0.1/` and trick the
            // proxy into following into the internal network. The bridge
            // surfaces 30x responses verbatim so apps can implement their
            // own redirect logic if they need to.
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'DSGoApps/' . (defined('DSGO_APPS_VERSION') ? DSGO_APPS_VERSION : '0'),
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        if (self::$transport_factory !== null) {
            $response = (self::$transport_factory)($url, $args);
        } else {
            if (!self::transport_supports_ip_pinning()) {
                return self::log_and_error($log_ctx,
                    'http_transport_unsupported',
                    'HTTP proxy requires the cURL transport with CURLOPT_RESOLVE support',
                    [],
                    $req_bytes);
            }
            // DNS-pin via CURLOPT_RESOLVE so the actual fetch reaches the
            // SSRF-validated IP rather than re-resolving (and getting a
            // private address from an attacker-controlled NS — classic
            // rebinding TOCTOU). Only effective on the cURL transport;
            // documented as a residual risk in the class docstring.
            $port   = isset($parsed['port']) ? (int) $parsed['port'] : 443;
            $pin_cb = self::build_curl_pin_callback($log_ctx['host'], $port, $prepared['resolved_ips']);
            add_action('http_api_curl', $pin_cb, 10, 3);
            try {
                $response = wp_remote_request($url, $args);
            } finally {
                remove_action('http_api_curl', $pin_cb, 10);
            }
        }

        if ($response instanceof \WP_Error) {
            $msg   = $response->get_error_message();
            $is_to = stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false;
            $code  = $is_to ? 'http_timeout' : 'http_network_error';
            return self::log_and_error($log_ctx, $code, $msg);
        }
        if (!is_array($response)) {
            return self::log_and_error($log_ctx,
                'http_network_error', 'transport returned an unexpected response shape');
        }

        return $response;
    }

    /**
     * Steps 11-13: response body cap + header strip, JSON auto-parse on a
     * json content-type, and the success audit-log row.
     *
     * Returns a success BridgeResult carrying the
     * `['status'=>int,'headers'=>array,'body'=>mixed]` triple, or a
     * BridgeResult error for an oversized response.
     *
     * @param array<string,mixed> $response Raw transport response array.
     * @param array{app_id:string,host:string,method:string,path:string,t_start:int} $log_ctx
     */
    private static function process_response(array $response, int $req_bytes, array $log_ctx): BridgeResult {
        // Step 11: response cap + header strip.
        $resp_body  = (string) ($response['body'] ?? '');
        $resp_bytes = strlen($resp_body);
        $resp_cap   = (int) apply_filters('dsgo_apps_http_response_max_bytes', self::DEFAULT_RESPONSE_MAX_BYTES);
        if ($resp_bytes > $resp_cap) {
            return self::log_and_error($log_ctx,
                'http_response_too_large',
                sprintf('response body is %d bytes (max %d)', $resp_bytes, $resp_cap),
                [],
                /* req_bytes */ $req_bytes,
                /* resp_bytes */ $resp_bytes);
        }
        $status       = (int) ($response['response']['code'] ?? 0);
        $safe_headers = self::sanitize_response_headers($response['headers'] ?? []);

        // Step 12: JSON auto-parse.
        $content_type = '';
        foreach ($safe_headers as $k => $v) {
            if (strcasecmp($k, 'content-type') === 0) { $content_type = (string) $v; break; }
        }
        $body_out = $resp_body;
        if (preg_match('#application/(?:[\w.+-]+\+)?json#i', $content_type)) {
            $decoded = json_decode($resp_body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body_out = $decoded;
            } else {
                // Keep raw, mark in headers so callers can detect.
                $safe_headers['X-Dsgo-Json-Parse-Error'] = '1';
            }
        }

        // Step 13: success audit-log row.
        Http_Proxy_Log::log(
            app_id: $log_ctx['app_id'],
            host: $log_ctx['host'],
            method: $log_ctx['method'],
            path: self::path_for_log($log_ctx['path']),
            status: $status,
            duration_ms: self::ms_since($log_ctx['t_start']),
            req_bytes: $req_bytes,
            resp_bytes: $resp_bytes,
        );

        return BridgeResult::ok([
            'status'  => $status,
            'headers' => $safe_headers,
            'body'    => $body_out,
        ]);
    }

    // --- step 4 helper: host matching ---

    private static function host_is_allowed(string $host, array $allowlist): bool {
        foreach ($allowlist as $pattern) {
            $pattern = strtolower((string) $pattern);
            if (str_starts_with($pattern, '*.')) {
                // Single-label wildcard. `*.notion.com` matches `api.notion.com`
                // but NOT `a.b.notion.com` (multi-label) and NOT `notion.com`
                // (bare apex).
                $suffix = '.' . substr($pattern, 2);
                if (!str_ends_with($host, $suffix)) continue;
                $prefix = substr($host, 0, strlen($host) - strlen($suffix));
                if ($prefix === '' || str_contains($prefix, '.')) continue;
                return true;
            }
            if ($pattern === $host) return true;
        }
        return false;
    }

    // --- step 5 helper: SSRF guard ---

    /**
     * Resolve $host and validate the IPs. Returns ['ips' => string[]] on
     * success — the caller pins one of those IPs via CURLOPT_RESOLVE — or
     * ['error' => string] on rejection (private/loopback range, self-target,
     * or unresolvable). Splitting "resolve" from "validate" lets step 10
     * pin the exact IP we validated, defeating DNS rebinding between this
     * check and the actual fetch.
     *
     * @return array{ips:string[]}|array{error:string}
     */
    private static function resolve_and_validate(string $host): array {
        $ips = (self::$dns_resolver !== null)
            ? (self::$dns_resolver)($host)
            : self::default_resolve($host);
        if ($ips === []) {
            return ['error' => 'dns: unresolvable host'];
        }
        foreach ($ips as $ip) {
            if (self::ip_is_private((string) $ip)) {
                return ['error' => 'private/loopback: ' . $ip];
            }
        }
        // Self-target guard: if the host's IPs intersect the WP site's
        // own A records, refuse — apps must not loop back into this site
        // via the proxy.
        $site_host = wp_parse_url((string) home_url(), PHP_URL_HOST);
        if (is_string($site_host) && $site_host !== '' && strtolower($site_host) !== $host) {
            $site_ips = self::default_resolve(strtolower($site_host));
            if ($site_ips !== [] && array_intersect($ips, $site_ips) !== []) {
                return ['error' => 'self-target via DNS'];
            }
        }
        return ['ips' => array_values(array_map('strval', $ips))];
    }

    /**
     * Build the cURL pin callback that runs inside `http_api_curl`. The
     * callback sets CURLOPT_RESOLVE for $host:$port to the first IP in
     * $ips, so cURL uses that exact address instead of re-resolving via
     * DNS at fetch time. The TLS handshake still validates the cert
     * against $host (SNI + CN match), so this does not weaken cert
     * checking — it only pins the network address.
     *
     * @param string[] $ips Non-empty, already validated against private ranges.
     */
    private static function build_curl_pin_callback(string $host, int $port, array $ips): \Closure {
        return static function ($handle) use ($host, $port, $ips): void {
            if (!is_resource($handle) && !(is_object($handle) && $handle instanceof \CurlHandle)) {
                return;
            }
            if (!function_exists('curl_setopt') || !defined('CURLOPT_RESOLVE')) {
                return;
            }
            $entry = sprintf('%s:%d:%s', $host, $port, $ips[0]);
            curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
        };
    }

    private static function transport_supports_ip_pinning(): bool {
        return function_exists('curl_setopt') && defined('CURLOPT_RESOLVE');
    }

    /**
     * @return string[] Empty array on resolution failure.
     */
    private static function default_resolve(string $host): array {
        $v4 = @gethostbynamel($host);
        if ($v4 === false) $v4 = [];
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (!is_array($v6)) $v6 = [];
        $v6_addrs = array_values(array_filter(array_map(
            static fn ($r) => is_array($r) && isset($r['ipv6']) ? (string) $r['ipv6'] : null,
            $v6,
        )));
        return array_values(array_filter(array_merge($v4, $v6_addrs), 'is_string'));
    }

    /**
     * Private/loopback/link-local/multicast check for IPv4 and IPv6.
     * IPv4 is checked via CIDR membership (no shelling out); IPv6 is
     * checked via well-known string prefixes (loopback `::1`, link-local
     * `fe80::/10`, unique-local `fc00::/7`, multicast `ff00::/8`).
     */
    private static function ip_is_private(string $ip): bool {
        $packed = @inet_pton($ip);
        if ($packed === false) return false;

        // IPv4 in 4 bytes
        if (strlen($packed) === 4) {
            $long = unpack('N', $packed);
            if ($long === false) return false;
            $long = $long[1];
            foreach (self::ipv4_blocked_ranges() as [$net, $bits]) {
                $mask  = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                $net_l = unpack('N', inet_pton($net))[1];
                if (($long & $mask) === ($net_l & $mask)) return true;
            }
            return false;
        }

        // IPv6 in 16 bytes — compare against canonical loopback, then
        // bit-test against link-local (fe80::/10), unique-local (fc00::/7),
        // and multicast (ff00::/8).
        if (strlen($packed) === 16) {
            if ($packed === inet_pton('::1')) return true;
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            if (($b0 & 0xFE) === 0xFC) return true;             // fc00::/7
            if ($b0 === 0xFF) return true;                       // ff00::/8
            if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) return true; // fe80::/10
            // ::ffff:0:0/96 — IPv4-mapped — recurse on the trailing 4 bytes.
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")) {
                $mapped = substr($packed, 12);
                return self::ip_is_private(inet_ntop($mapped) ?: '');
            }
            return false;
        }
        return false;
    }

    /** @return array<int, array{0:string,1:int}> */
    private static function ipv4_blocked_ranges(): array {
        return [
            ['0.0.0.0',     8],   // this network
            ['10.0.0.0',    8],   // RFC1918
            ['100.64.0.0',  10],  // CGNAT (RFC6598)
            ['127.0.0.0',   8],   // loopback
            ['169.254.0.0', 16],  // link-local
            ['172.16.0.0',  12],  // RFC1918
            ['192.0.2.0',   24],  // TEST-NET-1
            ['192.168.0.0', 16],  // RFC1918
            ['198.18.0.0',  15],  // benchmarking (RFC2544)
            ['198.51.100.0',24],  // TEST-NET-2
            ['203.0.113.0', 24],  // TEST-NET-3
            ['224.0.0.0',   4],   // multicast
            ['240.0.0.0',   4],   // reserved
        ];
    }

    // --- step 6 helper: outbound header sanitize ---

    /**
     * Populate $out with the cleaned header map. Returns null on success,
     * or an error message string on failure (e.g. invalid header name).
     */
    private static function sanitize_headers(array $raw, ?array &$out): ?string {
        $out = [];
        foreach ($raw as $name => $value) {
            if (!is_string($name) || $name === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $name)) {
                return sprintf('header name "%s" contains invalid characters', is_string($name) ? $name : '(non-string)');
            }
            $lname = strtolower($name);
            if (in_array($lname, self::BLOCKED_HEADERS, true)) continue;
            if (str_starts_with($lname, 'x-forwarded-')) continue;
            if (str_starts_with($lname, 'proxy-')) continue;
            $out[$name] = (string) $value;
        }
        return null;
    }

    // --- step 8 helper: secret substitution ---

    /**
     * Substitute {{ALIAS}} tokens in $headers and $body using Secret_Vault.
     * Returns null on success, or ['code'=>..., 'message'=>...] on failure.
     */
    private static function substitute_secrets(Manifest $m, array &$headers, ?string &$body): ?array {
        $declared = array_map(
            static fn (array $row): string => $row['alias'],
            $m->secrets,
        );

        $sub = function (string $input) use ($m, $declared): array {
            if (!preg_match_all('/\{\{([A-Z][A-Z0-9_]{0,63})\}\}/', $input, $matches)) {
                return ['value' => $input];
            }
            foreach (array_unique($matches[1]) as $alias) {
                if (!in_array($alias, $declared, true)) {
                    return ['code' => 'http_unknown_secret',
                            'message' => sprintf('secret alias "%s" is not declared in manifest.secrets', $alias)];
                }
                $value = Secret_Vault::get($m->id, $alias);
                if ($value === null) {
                    return ['code' => 'http_secret_not_set',
                            'message' => sprintf('secret "%s" has not been configured by the site admin', $alias)];
                }
                $input = str_replace('{{' . $alias . '}}', $value, $input);
            }
            return ['value' => $input];
        };

        foreach ($headers as $k => $v) {
            $r = $sub((string) $v);
            if (isset($r['code'])) return $r;
            $headers[$k] = $r['value'];
        }
        if ($body !== null) {
            $r = $sub($body);
            if (isset($r['code'])) return $r;
            $body = $r['value'];
        }
        return null;
    }

    // --- step 9 helper: rate limit ---

    /**
     * Returns null if the call is within the per-minute budget, or
     * ['retry_after_seconds' => int] when the budget is exhausted.
     */
    private static function rate_limit_check(string $app_id): ?array {
        $limit  = (int) apply_filters('dsgo_apps_http_rate_per_minute', self::DEFAULT_RATE_PER_MINUTE);
        if ($limit <= 0) return null;   // disabled
        // Per-app per-minute bucket. The key + the 90s TTL (covers the
        // minute boundary so a request landing at sec 59 doesn't reset the
        // counter for the next minute) stay owned here; the shared
        // Rate_Limiter just runs the read-modify-write counter core.
        $bucket = 'dsgo_apps_http_rl_' . $app_id . '_' . (int) (time() / 60);
        if (!Rate_Limiter::try_acquire($bucket, $limit, self::RATE_LIMIT_TTL_SECONDS)) {
            $retry_after = 60 - (time() % 60);
            return ['retry_after_seconds' => $retry_after > 0 ? $retry_after : 1];
        }
        return null;
    }

    // --- step 11 helper: response header sanitize ---

    /**
     * Strip Set-Cookie / Set-Cookie2 from the response headers before
     * surfacing them to the app. Normalized to a plain assoc array (a
     * Requests CaseInsensitiveDictionary survives the cast).
     *
     * @param mixed $headers
     * @return array<string, string>
     */
    private static function sanitize_response_headers($headers): array {
        $out = [];
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            // WpHttp Requests dictionary
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) return $out;
        foreach ($headers as $k => $v) {
            if (!is_string($k)) continue;
            $lk = strtolower($k);
            if ($lk === 'set-cookie' || $lk === 'set-cookie2') continue;
            if (is_array($v)) $v = implode(', ', array_map('strval', $v));
            $out[$k] = (string) $v;
        }
        return $out;
    }

    // --- logging helpers ---

    private static function ms_since(int $t_start): int {
        return (int) ((hrtime(true) - $t_start) / 1_000_000);
    }

    /**
     * Strip query string from the path before logging — the query may
     * carry secrets, OAuth codes, or user identifiers the audit log
     * should not retain.
     */
    private static function path_for_log(string $path): string {
        $q = strpos($path, '?');
        return $q === false ? $path : substr($path, 0, $q);
    }

    /**
     * Compose: write a status=0 audit-log row (no upstream response made it
     * back) and return the matching BridgeResult error. Used by every
     * step 2-12 short-circuit so the audit trail tracks attempted requests,
     * not just successful ones.
     *
     * `$extra` carries optional error-detail fields (e.g.
     * `retry_after_seconds`) — they ride through BridgeResult::error()'s
     * `$details` and are surfaced verbatim by to_http_object().
     *
     * @param array{app_id:string,host:string,method:string,path:string,t_start:int} $log_ctx
     * @param array<string,mixed> $extra
     */
    private static function log_and_error(
        array $log_ctx,
        string $code,
        string $message,
        array $extra = [],
        int $req_bytes = 0,
        int $resp_bytes = 0,
    ): BridgeResult {
        Http_Proxy_Log::log(
            app_id: $log_ctx['app_id'],
            host: $log_ctx['host'],
            method: $log_ctx['method'],
            path: self::path_for_log($log_ctx['path']),
            status: 0,
            duration_ms: self::ms_since($log_ctx['t_start']),
            req_bytes: $req_bytes,
            resp_bytes: $resp_bytes,
        );
        return BridgeResult::error($code, $message, $extra);
    }
}
