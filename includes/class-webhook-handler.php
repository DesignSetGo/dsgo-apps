<?php
/**
 * Webhook delivery pipeline — the 10-step handler.
 *
 * Called from WebhookRouter (Task 13) when a request lands on a
 * registered `dsgo/v1/webhooks/<app>/<endpoint>` route. Executes
 * the pipeline once per request, returning a WP_REST_Response with
 * the appropriate HTTP status and body shape.
 *
 *   1. Load endpoint config from manifest                 → 404 if missing.
 *   2. Rate-limit check                                   → 429 if exhausted.
 *   3. Build safe headers + raw body.
 *   4. Verify auth                                        → 401 mismatch,
 *                                                          503 missing secret.
 *   5. Idempotency check                                  → 200 idempotent:true.
 *   6. Async path                                         → 200 queued:true.
 *   7. Sync path: resolve ability                         → 404, 503 inactive.
 *   8. Invoke ability                                     → 500 on WP_Error /
 *                                                          throwable, 503 on
 *                                                          execute_php_class_not_loadable.
 *   9. Set idempotency transient (sync success only).
 *  10. Write WebhookLog row + return.
 *
 * Response error bodies surface a sanitized `error_code` field but no
 * provider-side detail — auth failures must not reveal whether the
 * signature, the timestamp, or the secret was the missing piece.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookHandler {

    /** Default per-endpoint rate limit when the manifest omits the field. */
    private const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;

    /**
     * Headers that must be stripped before passing to the ability or
     * encoding into the async queue. These carry the auth credential
     * itself — handing them to user code re-exposes the secret to a
     * surface that shouldn't see it.
     *
     * Lowercase because Build_safe_headers() normalizes inbound keys.
     */
    private const REDACTED_HEADERS = [
        'authorization',
        'stripe-signature',
        'x-hub-signature-256',
        'x-slack-signature',
        'x-webhook-signature',
    ];

    /**
     * Thin orchestrator for the 10-step webhook pipeline. Each cohesive
     * step group lives in its own private method that either short-circuits
     * with a WP_REST_Response or returns null/its value to continue. The
     * step order, status codes, and body shapes are unchanged.
     */
    public static function handle(\WP_REST_Request $req, string $app_id, string $endpoint_id): \WP_REST_Response {
        $start_ms = self::now_ms();

        // Step 1: load endpoint config from the app's stored manifest.
        $endpoint = self::load_endpoint_config($app_id, $endpoint_id);
        if ($endpoint === null) {
            return self::respond_error(404, 'endpoint_not_found', 'Endpoint not registered.', $app_id, $endpoint_id, $start_ms);
        }

        // Step 2: rate limit.
        $rate_limited = self::check_rate_limit($endpoint, $app_id, $endpoint_id, $start_ms);
        if ($rate_limited !== null) {
            return $rate_limited;
        }

        // Step 3: pull the raw body + headers off the request. The body
        // bytes MUST be the exact bytes the signature was computed over.
        $raw_body = $req->get_body();
        if (!is_string($raw_body)) {
            $raw_body = '';
        }
        $headers_lower = self::lowercase_headers($req->get_headers());

        // Step 4: auth.
        $auth_failure = self::verify_auth($endpoint, $raw_body, $headers_lower, $app_id, $endpoint_id, $start_ms);
        if ($auth_failure !== null) {
            return $auth_failure;
        }

        // Step 5: idempotency, when the endpoint declares an event-id
        // header. Missing or empty event id falls through (the
        // idempotency check itself short-circuits on empty input).
        $event_id = '';
        if (isset($endpoint['idempotency_header']) && is_string($endpoint['idempotency_header'])) {
            $event_id = (string) ($headers_lower[strtolower($endpoint['idempotency_header'])] ?? '');
            if ($event_id !== '' && WebhookIdempotency::check($app_id, $endpoint_id, $event_id)) {
                return self::respond_success(200, ['ok' => true, 'idempotent' => true], $app_id, $endpoint_id, $start_ms, async: false);
            }
        }

        // Step 6: async path — terminal. enqueue + single-event schedule +
        // 200 queued:true immediately.
        if (!empty($endpoint['async'])) {
            return self::dispatch_async($endpoint, $headers_lower, $raw_body, $event_id, $app_id, $endpoint_id, $start_ms);
        }

        // Steps 7-10: sync path — terminal. resolve ability, invoke, set
        // idempotency transient on success, log + return.
        return self::dispatch_sync($endpoint, $headers_lower, $raw_body, $event_id, $app_id, $endpoint_id, $start_ms);
    }

    /**
     * Step 2: per-endpoint rate limit. The manifest validator guarantees
     * the value is 1..600; the default kicks in only when authors omit the
     * field entirely. Returns a 429 WP_REST_Response (with Retry-After) when
     * the budget is exhausted, or null to continue.
     *
     * @param array<string, mixed> $endpoint
     */
    private static function check_rate_limit(array $endpoint, string $app_id, string $endpoint_id, int $start_ms): ?\WP_REST_Response {
        $limit = isset($endpoint['rate_limit_per_minute']) && is_int($endpoint['rate_limit_per_minute'])
            ? $endpoint['rate_limit_per_minute']
            : self::DEFAULT_RATE_LIMIT_PER_MINUTE;
        if (!WebhookRateLimiter::try_acquire($app_id, $endpoint_id, $limit)) {
            $response = self::respond_error(429, 'rate_limited', 'Too many requests.', $app_id, $endpoint_id, $start_ms);
            $response->header('Retry-After', '60');
            return $response;
        }
        return null;
    }

    /**
     * Step 4: verify the inbound request's auth credential. Auth failures
     * use 401; the special webhook_secret_not_set case from WebhookAuth maps
     * to 503 because it's an operator-visible misconfiguration, not a
     * signature problem. Returns the error WP_REST_Response, or null on
     * success.
     *
     * @param array<string, mixed>  $endpoint
     * @param array<string, string> $headers_lower
     */
    private static function verify_auth(array $endpoint, string $raw_body, array $headers_lower, string $app_id, string $endpoint_id, int $start_ms): ?\WP_REST_Response {
        $auth_result = WebhookAuth::verify($endpoint, $raw_body, $headers_lower, $app_id);
        if (is_wp_error($auth_result)) {
            $code   = $auth_result->get_error_code();
            $status = $code === 'webhook_secret_not_set' ? 503 : 401;
            return self::respond_error($status, $code, $auth_result->get_error_message(), $app_id, $endpoint_id, $start_ms);
        }
        return null;
    }

    /**
     * Step 6: async dispatch — terminal. Enqueues the encrypted payload,
     * schedules the single-event async hook, and returns 200 queued:true
     * immediately. Does NOT set the idempotency cache here —
     * AsyncWebhookHandler::run sets it on actual success.
     *
     * @param array<string, mixed>  $endpoint
     * @param array<string, string> $headers_lower
     */
    private static function dispatch_async(array $endpoint, array $headers_lower, string $raw_body, string $event_id, string $app_id, string $endpoint_id, int $start_ms): \WP_REST_Response {
        $safe_headers = self::safe_headers($headers_lower, $endpoint);
        $row_id = AsyncWebhookHandler::enqueue(
            $app_id,
            $endpoint_id,
            $event_id !== '' ? $event_id : null,
            $raw_body,
            (string) wp_json_encode($safe_headers),
        );
        if ($row_id === 0) {
            return self::respond_error(500, 'webhook_enqueue_failed', 'Could not enqueue webhook.', $app_id, $endpoint_id, $start_ms);
        }
        wp_schedule_single_event(time(), AsyncWebhookHandler::ASYNC_HOOK, [$row_id]);
        return self::respond_success(200, ['ok' => true, 'queued' => true], $app_id, $endpoint_id, $start_ms, async: true);
    }

    /**
     * Steps 7-10: sync dispatch — terminal. Resolves the bound ability,
     * invokes it (catching Throwables so an ability crash never propagates
     * to the REST layer), records the idempotency transient on success, and
     * writes the WebhookLog row via respond_*().
     *
     * @param array<string, mixed>  $endpoint
     * @param array<string, string> $headers_lower
     */
    private static function dispatch_sync(array $endpoint, array $headers_lower, string $raw_body, string $event_id, string $app_id, string $endpoint_id, int $start_ms): \WP_REST_Response {
        // Step 7: resolve the ability.
        $ability_name = $endpoint['ability'] ?? null;
        if (!is_string($ability_name)
            || !function_exists('wp_has_ability')
            || !function_exists('wp_get_ability')
        ) {
            return self::respond_error(404, 'webhook_ability_not_found', 'Ability not registered.', $app_id, $endpoint_id, $start_ms);
        }
        AbilitiesPublisher::register_all();
        if (!wp_has_ability($ability_name)) {
            return self::respond_error(404, 'webhook_ability_not_found', 'Ability not registered.', $app_id, $endpoint_id, $start_ms);
        }
        $ability = wp_get_ability($ability_name);
        if (!$ability) {
            return self::respond_error(404, 'webhook_ability_not_found', 'Ability not registered.', $app_id, $endpoint_id, $start_ms);
        }

        // Step 8: invoke. Catch Throwables explicitly so an ability
        // crash never propagates to the REST layer.
        $safe_headers = self::safe_headers($headers_lower, $endpoint);
        $input = [
            'body'    => json_decode($raw_body, true),
            'raw'     => $raw_body,
            'headers' => $safe_headers,
            'method'  => 'POST',
        ];
        try {
            $result = $ability->execute($input);
        } catch (\Throwable $e) {
            return self::respond_error(500, 'webhook_ability_exception', $e->getMessage(), $app_id, $endpoint_id, $start_ms);
        }

        if (is_wp_error($result)) {
            // execute_php_class_not_loadable means the companion plugin
            // isn't installed — surface as 503 so the sender retries
            // later rather than treating it as a permanent 500.
            $code = $result->get_error_code() === 'execute_php_class_not_loadable'
                ? 'execute_php_class_not_loadable'
                : 'webhook_ability_failed';
            $status = $code === 'execute_php_class_not_loadable' ? 503 : 500;
            return self::respond_error($status, $code, $result->get_error_message(), $app_id, $endpoint_id, $start_ms);
        }

        // Step 9: success → set idempotency transient.
        if ($event_id !== '') {
            WebhookIdempotency::record($app_id, $endpoint_id, $event_id);
        }

        // Step 10: log + return.
        return self::respond_success(200, ['ok' => true], $app_id, $endpoint_id, $start_ms, async: false);
    }

    /**
     * Load the manifest's `webhooks.endpoints[]` entry matching the
     * pair. Returns null if the app, manifest, or endpoint is missing.
     * Delegates the post + manifest lookup to App_Repository so the
     * webhook router / async handler / cron dispatcher share one query.
     *
     * @return array<string, mixed>|null
     */
    private static function load_endpoint_config(string $app_id, string $endpoint_id): ?array {
        return App_Repository::endpoint_config($app_id, $endpoint_id);
    }

    /**
     * Normalize WP_REST_Request's header map back to the wire-format
     * header names that WebhookAuth + the idempotency lookup expect.
     *
     * WP_REST_Request stores headers with the key normalized to
     * underscore_form: `Stripe-Signature` becomes `stripe_signature`,
     * `X-Hub-Signature-256` becomes `x_hub_signature_256`. Convert
     * those underscores back to hyphens here so the downstream
     * consumers see canonical lowercase-hyphen header names. Values
     * come back as arrays (one per same-name header); flatten to the
     * first value — every webhook provider sends a single value.
     *
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function lowercase_headers(array $headers): array {
        $out = [];
        foreach ($headers as $k => $v) {
            if (!is_string($k)) continue;
            $value = is_array($v) ? ($v[0] ?? '') : $v;
            if (!is_string($value)) continue;
            $normalized = strtolower(str_replace('_', '-', $k));
            $out[$normalized] = $value;
        }
        return $out;
    }

    /**
     * Strip headers carrying the auth credential before exposing the
     * header map to the ability or the async queue. Returns a NEW
     * array so the original (still in scope for auth verification)
     * isn't mutated.
     *
     * @param array<string, string> $headers_lower
     * @param array<string, mixed>  $endpoint
     * @return array<string, string>
     */
    private static function safe_headers(array $headers_lower, array $endpoint): array {
        $redacted = self::REDACTED_HEADERS;
        $safe = [];
        foreach ($headers_lower as $k => $v) {
            if (!in_array($k, $redacted, true)) {
                $safe[$k] = $v;
            }
        }
        return $safe;
    }

    /**
     * Build a successful WP_REST_Response and write the matching
     * WebhookLog entry.
     *
     * @param array<string, mixed> $body
     */
    private static function respond_success(int $status, array $body, string $app_id, string $endpoint_id, int $start_ms, bool $async): \WP_REST_Response {
        WebhookLog::insert([
            'app_id'      => $app_id,
            'endpoint_id' => $endpoint_id,
            'received_at' => current_time('mysql', true),
            'duration_ms' => self::now_ms() - $start_ms,
            'http_status' => $status,
            'status'      => 'ok',
            'async'       => $async,
            'error_code'  => null,
            'error_msg'   => null,
        ]);
        return new \WP_REST_Response($body, $status);
    }

    /**
     * Build an error WP_REST_Response and write the matching
     * WebhookLog entry. error_code is surfaced in the body but
     * verbose error_msg is NOT — keeps unauthenticated callers
     * from learning which auth step rejected them.
     */
    private static function respond_error(int $status, string $error_code, string $error_msg, string $app_id, string $endpoint_id, int $start_ms): \WP_REST_Response {
        WebhookLog::insert([
            'app_id'      => $app_id,
            'endpoint_id' => $endpoint_id,
            'received_at' => current_time('mysql', true),
            'duration_ms' => self::now_ms() - $start_ms,
            'http_status' => $status,
            'status'      => 'error',
            'async'       => false,
            'error_code'  => $error_code,
            'error_msg'   => substr($error_msg, 0, 1000),
        ]);
        return new \WP_REST_Response(
            ['error_code' => $error_code],
            $status,
        );
    }

    private static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }
}
