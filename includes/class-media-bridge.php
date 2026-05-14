<?php
/**
 * Bridge handler for dsgo.media.upload().
 *
 * Wraps WordPress's upload pipeline (wp_handle_upload + wp_insert_attachment)
 * so apps can promote a generated image (Canvas, SVG export, AI render, etc.)
 * into a real WP media-library attachment that admins can find under Media →
 * Library and re-use in posts, pages, and blocks.
 *
 * This is a core, opt-out feature: every app gets it unless its manifest sets
 * `media.uploads: false`. Capability gating piggybacks on WP's existing
 * `upload_files` cap, so anonymous visitors and contributors are rejected by
 * the REST permission_callback before the bridge ever runs.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class MediaBridge {

    /** Per-app per-hour upload cap. Override via the `dsgo_apps_media_rate_limit_per_hour` filter. */
    public const RATE_LIMIT_PER_HOUR = 60;

    /** Hard max file size (bytes). Override via the `dsgo_apps_media_max_bytes` filter. */
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /** Meta key marking attachments uploaded through the bridge. Used for audit and uninstall cleanup. */
    public const SOURCE_META_KEY = '_dsgo_apps_source_app';

    /** @var \Closure|null Test seam: replaces wp_handle_upload() so suites don't touch the FS. */
    private static ?\Closure $upload_handler_override = null;

    public static function set_upload_handler_for_tests(?\Closure $handler): void {
        self::$upload_handler_override = $handler;
    }

    /**
     * Allowed MIME types for app-uploaded media. Defaults to the image set —
     * apps generate pictures, not arbitrary executables. Site admins can
     * broaden the list via the `dsgo_apps_media_allowed_mimes` filter.
     *
     * @return array<string,string> ext-pattern => mime-type, in WP's `get_allowed_mime_types()` shape
     */
    public static function allowed_mimes(): array {
        $defaults = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'svg'          => 'image/svg+xml',
        ];
        $filtered = apply_filters('dsgo_apps_media_allowed_mimes', $defaults);
        return is_array($filtered) ? $filtered : $defaults;
    }

    /**
     * Whether this app is permitted to call `media.upload`. Two gates:
     *   1. Manifest opt-out: `media.uploads === false` disables the feature.
     *   2. Site filter: `dsgo_apps_media_upload_allowed` lets admins disable
     *      per-app or globally (return false to reject).
     *
     * Capability checks (`upload_files`) live on the REST permission_callback,
     * so this method only handles the bridge-level opt-out logic.
     */
    public static function is_enabled_for_app(Manifest $manifest): bool {
        if ($manifest->media_uploads_enabled === false) {
            return false;
        }
        return (bool) apply_filters('dsgo_apps_media_upload_allowed', true, $manifest);
    }

    /**
     * Promote an uploaded file into a media-library attachment and return
     * the wire-format result array.
     *
     * Builds a BridgeResult internally and serializes it via to_array() at
     * the boundary — the emitted shape (`ok` + `data` on success; `ok`,
     * `code`, `message` on failure) is byte-identical to the inline arrays
     * this method built before.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param array{filename?:mixed,alt_text?:mixed} $params
     * @return array{ok:bool,data?:array,code?:string,message?:string}
     */
    public static function upload(Manifest $manifest, int $visitor_user_id, array $file, array $params = []): array {
        return self::upload_result($manifest, $visitor_user_id, $file, $params)->to_array();
    }

    /**
     * Internal BridgeResult-returning core of upload(). Kept separate so the
     * public method stays a thin to_array() boundary.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param array{filename?:mixed,alt_text?:mixed} $params
     */
    private static function upload_result(Manifest $manifest, int $visitor_user_id, array $file, array $params = []): BridgeResult {
        if (!self::is_enabled_for_app($manifest)) {
            return BridgeResult::error('permission_denied', 'media uploads are disabled for this app');
        }

        // The REST callback already verified the visitor has `upload_files`,
        // but we re-check inside the bridge so a future direct invocation
        // (e.g. from a unit test or alternative caller) can't bypass the gate.
        if ($visitor_user_id <= 0 || !user_can($visitor_user_id, 'upload_files')) {
            return BridgeResult::error('permission_denied', 'visitor lacks "upload_files" capability');
        }

        if (empty($file) || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            return BridgeResult::error('invalid_params', 'expected multipart "file" field');
        }
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return BridgeResult::error('invalid_params', sprintf('upload error code %d', $file['error']));
        }

        $max_bytes = (int) apply_filters('dsgo_apps_media_max_bytes', self::DEFAULT_MAX_BYTES, $manifest->id);
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0 && is_readable($file['tmp_name'])) {
            $size = (int) filesize($file['tmp_name']);
        }
        if ($size <= 0) {
            return BridgeResult::error('invalid_params', 'uploaded file is empty');
        }
        if ($size > $max_bytes) {
            return BridgeResult::error('payload_too_large',
                sprintf('file exceeds %d bytes (got %d)', $max_bytes, $size));
        }

        if (self::is_rate_limited($manifest->id)) {
            $cap = (int) apply_filters('dsgo_apps_media_rate_limit_per_hour', self::RATE_LIMIT_PER_HOUR, $manifest->id);
            return BridgeResult::error('rate_limited', sprintf('app exceeded %d uploads/hour', $cap));
        }

        $override_filename = isset($params['filename']) && is_string($params['filename']) && $params['filename'] !== ''
            ? sanitize_file_name($params['filename'])
            : null;
        if ($override_filename !== null && $override_filename !== '') {
            $file['name'] = $override_filename;
        } elseif (!isset($file['name']) || !is_string($file['name']) || $file['name'] === '') {
            // wp.apiFetch + FormData always supplies a name, but be defensive.
            $file['name'] = 'app-upload-' . wp_generate_password(8, false, false);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Constrain MIME types to the app-media allowlist, regardless of the
        // site's broader `upload_mimes` filter. This keeps bridge uploads
        // confined to images even on sites that allow PDFs / videos / zips
        // through the regular media uploader.
        $allowed = self::allowed_mimes();
        $mimes_filter = static fn (array $existing): array => $allowed;
        add_filter('upload_mimes', $mimes_filter, 99);
        try {
            $handled = self::run_upload_handler($file);
        } finally {
            remove_filter('upload_mimes', $mimes_filter, 99);
        }

        if (!is_array($handled) || isset($handled['error'])) {
            $msg = is_array($handled) && isset($handled['error']) ? (string) $handled['error'] : 'wp_handle_upload failed';
            // wp_handle_upload's "Sorry, you are not allowed to upload this file type."
            // is the canonical signal for an unsupported MIME — surface as
            // invalid_params so apps can recover, not as internal_error.
            $code = stripos($msg, 'file type') !== false ? 'invalid_params' : 'internal_error';
            return BridgeResult::error($code, $msg);
        }

        $upload_path = (string) ($handled['file'] ?? '');
        $upload_url  = (string) ($handled['url']  ?? '');
        $upload_type = (string) ($handled['type'] ?? '');
        if ($upload_path === '' || $upload_url === '' || $upload_type === '') {
            return BridgeResult::error('internal_error', 'wp_handle_upload returned an incomplete result');
        }

        $title = sanitize_text_field(pathinfo($file['name'], PATHINFO_FILENAME));
        if ($title === '') $title = 'App upload';

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $upload_type,
                'post_title'     => $title,
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_author'    => $visitor_user_id,
            ],
            $upload_path,
            0,
            true,
        );
        if (is_wp_error($attachment_id) || !is_int($attachment_id) || $attachment_id <= 0) {
            // Best-effort cleanup of the orphaned upload.
            if (is_file($upload_path)) {
                wp_delete_file($upload_path);
            }
            $msg = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'wp_insert_attachment failed';
            return BridgeResult::error('internal_error', (string) $msg);
        }

        // Record provenance so admins can audit which app produced an asset
        // (Media Library → file detail → Custom Fields shows it). Also lets
        // the uninstaller scrub bridge-uploaded media if site policy demands.
        update_post_meta($attachment_id, self::SOURCE_META_KEY, $manifest->id);

        $alt_input = isset($params['alt_text']) && is_string($params['alt_text']) ? trim($params['alt_text']) : '';
        // Sanitize once and reuse — the response's `alt_text` is contractually
        // the value saved against the attachment, so it must match what
        // `_wp_attachment_image_alt` actually holds (e.g. tags stripped).
        $alt_saved = $alt_input !== '' ? sanitize_text_field($alt_input) : '';
        if ($alt_saved !== '') {
            // Mirror the convention used by core media editing UI.
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_saved);
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $upload_path);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $width  = is_array($metadata) && isset($metadata['width'])  ? (int) $metadata['width']  : null;
        $height = is_array($metadata) && isset($metadata['height']) ? (int) $metadata['height'] : null;

        do_action('dsgo_apps_media_uploaded', [
            'attachment_id' => $attachment_id,
            'app_id'        => $manifest->id,
            'user_id'       => $visitor_user_id,
            'mime_type'     => $upload_type,
            'size'          => $size,
        ]);

        return BridgeResult::ok([
            'id'        => $attachment_id,
            'url'       => $upload_url,
            'mime_type' => $upload_type,
            'filename'  => wp_basename($upload_path),
            'width'     => $width,
            'height'    => $height,
            'alt_text'  => $alt_saved,
        ]);
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    private static function run_upload_handler(array $file): array {
        if (self::$upload_handler_override !== null) {
            $result = (self::$upload_handler_override)($file);
            return is_array($result) ? $result : ['error' => 'test override returned non-array'];
        }
        // `test_form => false` skips wp_nonce + action checks that are meant
        // for /wp-admin form submissions. The REST permission_callback has
        // already authenticated this request via the WP cookie + REST nonce.
        $result = wp_handle_upload($file, ['test_form' => false]);
        return is_array($result) ? $result : ['error' => 'wp_handle_upload returned non-array'];
    }

    private static function rate_counter_key(string $app_id): string {
        return sprintf('dsgo_media_rate_%s_%s', $app_id, gmdate('YmdH'));
    }

    private static function is_rate_limited(string $app_id): bool {
        $cap = (int) apply_filters('dsgo_apps_media_rate_limit_per_hour', self::RATE_LIMIT_PER_HOUR, $app_id);
        // Shared fixed-window counter; the per-hour bucket key and the
        // HOUR+60 TTL are media-specific and stay owned here. try_acquire
        // returns true when the call is permitted, so "rate limited" is
        // the negation.
        return !Rate_Limiter::try_acquire(
            self::rate_counter_key($app_id),
            $cap,
            HOUR_IN_SECONDS + 60,
        );
    }
}
