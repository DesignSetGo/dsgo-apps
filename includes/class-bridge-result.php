<?php
/**
 * Shared value object for bridge entrypoint results.
 *
 * Every `*-bridge` class historically built ad-hoc `array{ok:bool,...}` (or, in
 * Http_Proxy_Bridge's case, a `(object)`) return value with subtly different
 * error keys (`code` / `reason` / `wp_error_code` / `message`). BridgeResult
 * gives them ONE internal shape to build against; the bridge calls a
 * `to_*()` serializer at its public boundary so the wire format the JS / REST
 * consumers see does not change.
 *
 * Wire formats reproduced (verified against class-rest-api.php consumers):
 *
 *   to_array()       — AbilitiesBridge, CommerceBridge, AiBridge, EmailBridge,
 *                      MediaBridge. Success: ['ok'=>true,'data'=>$data].
 *                      Failure: ['ok'=>false,'code'=>$error_code,
 *                      'message'=>$error_message] plus any of the optional
 *                      keys 'reason' / 'wp_error_code' / 'status' that the
 *                      caller put in $error_details (only emitted when set,
 *                      matching the historical `isset()`-gated builders).
 *
 *   to_http_object() — Http_Proxy_Bridge only. Success:
 *                      (object){ok:true,status,headers,body}. Failure:
 *                      (object){error_code,message} plus any extra keys from
 *                      $error_details (e.g. retry_after_seconds). This is the
 *                      historical Http_Proxy_Bridge::fetch() shape.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

/**
 * @psalm-immutable
 */
final class BridgeResult {

    /**
     * @param bool                $ok            Whether the bridge call succeeded.
     * @param mixed               $data          Success payload (null on failure).
     * @param string              $error_code    Stable error code (empty on success).
     * @param string              $error_message Human-readable error message (empty on success).
     * @param array<string,mixed> $error_details Optional extra error fields — e.g.
     *                                           'reason', 'wp_error_code', 'status',
     *                                           'retry_after_seconds'. Serializers
     *                                           emit only the keys present here.
     */
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $data,
        public readonly string $error_code,
        public readonly string $error_message,
        public readonly array $error_details,
    ) {}

    /**
     * Build a success result carrying $data.
     *
     * @param mixed $data
     */
    public static function ok(mixed $data = null): self {
        return new self(true, $data, '', '', []);
    }

    /**
     * Build a failure result.
     *
     * @param array<string,mixed> $details Optional extra fields ('reason',
     *                                     'wp_error_code', 'status',
     *                                     'retry_after_seconds', ...).
     */
    public static function error(string $code, string $message, array $details = []): self {
        return new self(false, null, $code, $message, $details);
    }

    /**
     * Serialize to the associative-array wire format used by AbilitiesBridge,
     * CommerceBridge, AiBridge, EmailBridge, and MediaBridge.
     *
     * On success this is `['ok' => true, 'data' => $data]`. On failure it is
     * `['ok' => false, 'code' => ..., 'message' => ...]` with the optional
     * detail keys appended only when present — byte-identical to the inline
     * arrays the bridges built before this refactor.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array {
        if ($this->ok) {
            return ['ok' => true, 'data' => $this->data];
        }
        $out = [
            'ok'      => false,
            'code'    => $this->error_code,
            'message' => $this->error_message,
        ];
        // Historical builders only emitted these keys when set; preserve that
        // so consumers' isset() checks behave identically.
        foreach (['reason', 'wp_error_code', 'status'] as $key) {
            if (array_key_exists($key, $this->error_details)) {
                $out[$key] = $this->error_details[$key];
            }
        }
        return $out;
    }

    /**
     * Serialize to the stdClass wire format used by Http_Proxy_Bridge::fetch().
     *
     * Success: (object){ok:true, status, headers, body} — $data must be the
     * `['status'=>int,'headers'=>array,'body'=>mixed]` triple. `body` is
     * forwarded verbatim — including a literal `null` from a JSON-`null`
     * upstream response — via array_key_exists rather than `??`, matching
     * the legacy fetch() success object exactly.
     * Failure: (object){error_code, message} plus every key in $error_details
     * (e.g. retry_after_seconds), matching the legacy ::error() helper.
     */
    public function to_http_object(): object {
        if ($this->ok) {
            $data = is_array($this->data) ? $this->data : [];
            return (object) [
                'ok'      => true,
                'status'  => $data['status']  ?? 0,
                'headers' => $data['headers'] ?? [],
                'body'    => array_key_exists('body', $data) ? $data['body'] : '',
            ];
        }
        return (object) array_merge(
            ['error_code' => $this->error_code, 'message' => $this->error_message],
            $this->error_details,
        );
    }
}
