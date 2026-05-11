<?php
/**
 * Per-app and per-user key-value storage backed by WP post and user meta.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class StorageError extends \RuntimeException {
    public readonly string $error_code;
    public readonly string $bare_message;

    public function __construct(string $code, string $message) {
        $this->error_code   = $code;
        $this->bare_message = $message;
        // PHP exception message keeps the code prefix so logs/stack traces
        // remain self-describing; REST responses send `bare_message` to
        // avoid the client doubling the prefix when wrapping into a
        // BridgeRequestError.
        parent::__construct(sprintf('%s: %s', $code, $message));
    }
}

final class Storage {

    public const QUOTA_BYTES_PER_SCOPE = 256 * 1024;
    private const KEY_PATTERN = '/^[a-zA-Z0-9._-]{1,128}$/';

    private const APP_VALUE_PREFIX  = 'dsgo_apps_storage_app_';
    private const APP_SIZE_KEY      = 'dsgo_apps_storage_size_app';
    private const USER_VALUE_PREFIX = 'dsgo_apps_storage_user_';
    private const USER_SIZE_PREFIX  = 'dsgo_apps_storage_size_user_';

    public static function app_get(int $app_post_id, string $key): mixed {
        self::assert_key($key);
        $stored = get_post_meta($app_post_id, self::APP_VALUE_PREFIX . $key, true);
        return $stored === '' ? null : self::decode($stored);
    }

    public static function app_set(int $app_post_id, string $key, mixed $value): void {
        self::assert_key($key);
        $encoded = self::encode($value);
        $new_bytes = strlen($encoded);

        // Both meta reads share a single per-post cache hit on the first
        // get_post_meta(). Then update_post_meta() short-circuits when the
        // value is unchanged, so the size update only runs when the value
        // actually changed.
        $existing = get_post_meta($app_post_id, self::APP_VALUE_PREFIX . $key, true);
        if (is_string($existing) && $existing === $encoded) {
            return; // No-op write — value already matches.
        }
        $existing_bytes = is_string($existing) && $existing !== '' ? strlen($existing) : 0;
        $running = (int) get_post_meta($app_post_id, self::APP_SIZE_KEY, true);
        $projected = $running - $existing_bytes + $new_bytes;
        if ($projected > self::QUOTA_BYTES_PER_SCOPE) {
            throw new StorageError('payload_too_large', sprintf('app storage quota exceeded (%d > %d)', $projected, self::QUOTA_BYTES_PER_SCOPE));
        }
        update_post_meta($app_post_id, self::APP_VALUE_PREFIX . $key, $encoded);
        if ($projected !== $running) {
            update_post_meta($app_post_id, self::APP_SIZE_KEY, (string) $projected);
        }
    }

    public static function user_get(int $app_post_id, int $user_id, string $key): mixed {
        self::assert_key($key);
        if ($user_id <= 0) {
            return null;
        }
        $meta_key = self::USER_VALUE_PREFIX . $app_post_id . '_' . $key;
        $stored = get_user_meta($user_id, $meta_key, true);
        return $stored === '' ? null : self::decode($stored);
    }

    public static function user_set(int $app_post_id, int $user_id, string $key, mixed $value): void {
        self::assert_key($key);
        if ($user_id <= 0) {
            throw new StorageError('not_authenticated', 'user.set requires a logged-in user');
        }
        $encoded = self::encode($value);
        $new_bytes = strlen($encoded);

        $value_key = self::USER_VALUE_PREFIX . $app_post_id . '_' . $key;
        $size_key  = self::USER_SIZE_PREFIX . $app_post_id;
        $existing  = get_user_meta($user_id, $value_key, true);
        if (is_string($existing) && $existing === $encoded) {
            return; // No-op write — value already matches.
        }
        $existing_bytes = is_string($existing) && $existing !== '' ? strlen($existing) : 0;
        $running = (int) get_user_meta($user_id, $size_key, true);
        $projected = $running - $existing_bytes + $new_bytes;
        if ($projected > self::QUOTA_BYTES_PER_SCOPE) {
            throw new StorageError('payload_too_large', sprintf('user storage quota exceeded (%d > %d)', $projected, self::QUOTA_BYTES_PER_SCOPE));
        }
        update_user_meta($user_id, $value_key, $encoded);
        if ($projected !== $running) {
            update_user_meta($user_id, $size_key, (string) $projected);
        }
    }

    private static function assert_key(string $key): void {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new StorageError('invalid_params', sprintf('"%s" is not a valid storage key', $key));
        }
    }

    private static function encode(mixed $value): string {
        $encoded = wp_json_encode($value);
        if ($encoded === false) {
            throw new StorageError('invalid_params', 'value must be JSON-serializable');
        }
        return $encoded;
    }

    private static function decode(string $stored): mixed {
        return json_decode($stored, true);
    }
}
