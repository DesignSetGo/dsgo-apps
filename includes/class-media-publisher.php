<?php
/**
 * Promote bundled images to WP Media Library attachments at install time.
 *
 * Driven by the manifest's `media.publish[]` array of glob patterns.
 * Idempotent across re-deploys: attachment row is keyed by
 * (_dsgo_apps_source_app, _dsgo_apps_publish_path); a SHA-256 content-hash
 * meta key lets re-deploys skip unchanged files and replace changed ones
 * without churning attachment IDs.
 *
 * Spec: docs/superpowers/specs/2026-05-11-media-publish-design.md
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final readonly class PublishResult {
    public function __construct(
        public int $published,
        public int $updated,
        public int $skipped,
        public int $failed,
    ) {}
}

final class MediaPublisher {

    public const SOURCE_META_KEY  = '_dsgo_apps_source_app';
    public const PATH_META_KEY    = '_dsgo_apps_publish_path';
    public const HASH_META_KEY    = '_dsgo_apps_publish_hash';

    /**
     * Walk the bundle dir and return every relative file path that matches
     * any glob in $globs. Returns a sorted, deduplicated list.
     *
     * Glob semantics: PHP `fnmatch()` without FNM_PATHNAME — `*` matches
     * any chars including `/`. No `**` support (unnecessary).
     *
     * @param string[] $globs
     * @return string[]
     */
    public static function collect(string $bundle_dir, array $globs): array {
        if ($globs === [] || !is_dir($bundle_dir)) {
            return [];
        }
        // Normalize separators up-front so the substr() offset below is deterministic
        // regardless of whether the caller uses '/', '\\', or trailing slashes.
        $bundle_dir = rtrim(str_replace('\\', '/', $bundle_dir), '/');
        $matches    = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($bundle_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs      = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($abs, strlen($bundle_dir))), '/');
            foreach ($globs as $glob) {
                if (fnmatch($glob, $relative)) {
                    $matches[$relative] = true;
                    break;
                }
            }
        }
        $out = array_keys($matches);
        sort($out);
        return $out;
    }

    /**
     * Promote every bundled file matching the manifest's `media.publish`
     * globs into a real WP attachment. Best-effort: per-file failures are
     * logged and counted, never thrown.
     */
    public static function publish_for_app(Manifest $manifest, string $bundle_dir): PublishResult {
        if (!apply_filters('dsgo_apps_media_publish_enabled', true, $manifest)) {
            return new PublishResult(0, 0, 0, 0);
        }
        $globs = $manifest->media_publish_globs;
        if ($globs === []) {
            return new PublishResult(0, 0, 0, 0);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $published = $updated = $skipped = $failed = 0;
        foreach (self::collect($bundle_dir, $globs) as $relative) {
            $status = self::publish_file($manifest, $bundle_dir, $relative);
            match ($status) {
                'published' => $published++,
                'updated'   => $updated++,
                'skipped'   => $skipped++,
                default     => $failed++,
            };
        }
        return new PublishResult($published, $updated, $skipped, $failed);
    }

    /**
     * Publish one matched file. Returns the result status for aggregation.
     *
     * @return 'published'|'updated'|'skipped'|'failed'
     */
    private static function publish_file(Manifest $manifest, string $bundle_dir, string $relative): string {
        $abs = rtrim($bundle_dir, '/') . '/' . $relative;
        $real_bundle = realpath($bundle_dir);
        $real_abs    = realpath($abs);
        if ($real_bundle === false || $real_abs === false || !str_starts_with($real_abs, $real_bundle)) {
            self::log_skip($manifest->id, $relative, 'path escape');
            return 'failed';
        }
        if (!is_file($real_abs) || !is_readable($real_abs)) {
            self::log_skip($manifest->id, $relative, 'not readable');
            return 'failed';
        }

        $contents = file_get_contents($real_abs);
        if ($contents === false) {
            self::log_skip($manifest->id, $relative, 'read failed');
            return 'failed';
        }
        $hash = hash('sha256', $contents);

        $existing_id = self::lookup_attachment($manifest->id, $relative);
        if ($existing_id !== null) {
            $stored_hash = (string) get_post_meta($existing_id, self::HASH_META_KEY, true);
            if ($stored_hash === $hash) {
                return 'skipped';
            }
            return self::replace_attachment_file($existing_id, $real_abs, $relative, $hash)
                ? 'updated' : 'failed';
        }

        $new_id = self::publish_new_attachment($real_abs, $relative);
        if ($new_id === null) {
            return 'failed';
        }
        update_post_meta($new_id, self::SOURCE_META_KEY, $manifest->id);
        update_post_meta($new_id, self::PATH_META_KEY, $relative);
        update_post_meta($new_id, self::HASH_META_KEY, $hash);
        return 'published';
    }

    private static function lookup_attachment(string $app_id, string $relative): ?int {
        $found = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => self::SOURCE_META_KEY, 'value' => $app_id],
                ['key' => self::PATH_META_KEY,   'value' => $relative],
            ],
        ]);
        return $found !== [] ? (int) $found[0] : null;
    }

    /**
     * Copy $abs to a tmp location and call wp_handle_sideload so WP lands the
     * file in uploads/YYYY/MM/. Returns the sideload result on success, null
     * on failure. DOES NOT create an attachment row — that's the caller's job.
     *
     * Split out from publish_new_attachment so replace_attachment_file can
     * land a new file without creating a transient attachment post (and then
     * having to delete it, which would also delete the file via WP's
     * wp_delete_attachment hook).
     *
     * @return array{file:string,url:string,type:string}|null
     */
    private static function sideload_to_uploads(string $abs, string $relative): ?array {
        $tmp = wp_tempnam(basename($relative));
        if (!@copy($abs, $tmp)) {
            return null;
        }
        $file_array = [
            'name'     => basename($relative),
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize($abs),
        ];
        $allowed = MediaBridge::allowed_mimes();
        $filter  = static fn (array $existing): array => $allowed;
        add_filter('upload_mimes', $filter, 99);
        try {
            $handled = wp_handle_sideload($file_array, ['test_form' => false]);
        } finally {
            remove_filter('upload_mimes', $filter, 99);
        }
        if (!is_array($handled) || isset($handled['error'])) {
            if (is_file($tmp)) @unlink($tmp);
            return null;
        }
        return [
            'file' => (string) $handled['file'],
            'url'  => (string) $handled['url'],
            'type' => (string) $handled['type'],
        ];
    }

    /**
     * Sideload + create a brand-new attachment row. Returns the new ID on
     * success, null on failure. Caller is responsible for stamping the
     * SOURCE / PATH / HASH meta keys after this returns.
     */
    private static function publish_new_attachment(string $abs, string $relative): ?int {
        $handled = self::sideload_to_uploads($abs, $relative);
        if ($handled === null) {
            return null;
        }
        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $handled['type'],
                'post_title'     => sanitize_text_field(pathinfo(basename($relative), PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $handled['file'],
            0,
            true,
        );
        if (is_wp_error($attachment_id) || !is_int($attachment_id) || $attachment_id <= 0) {
            if (is_file($handled['file'])) wp_delete_file($handled['file']);
            return null;
        }
        $metadata = wp_generate_attachment_metadata($attachment_id, $handled['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        return $attachment_id;
    }

    /**
     * Replace the file backing an existing attachment in place. Same
     * attachment ID is preserved, so any posts referencing the attachment
     * continue to render — with the new image.
     *
     * The OLD file on disk is deleted after the new one is wired up (with
     * the path-equality guard, so a sideload that landed at the same
     * uploads/YYYY/MM path doesn't delete what it just wrote).
     */
    private static function replace_attachment_file(
        int $attachment_id,
        string $abs,
        string $relative,
        string $hash,
    ): bool {
        $old_path = get_attached_file($attachment_id);
        $handled  = self::sideload_to_uploads($abs, $relative);
        if ($handled === null) {
            return false;
        }
        update_attached_file($attachment_id, $handled['file']);
        $metadata = wp_generate_attachment_metadata($attachment_id, $handled['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        update_post_meta($attachment_id, self::HASH_META_KEY, $hash);

        if ($old_path && is_string($old_path) && is_file($old_path) && $old_path !== $handled['file']) {
            wp_delete_file($old_path);
        }
        return true;
    }

    private static function log_skip(string $app_id, string $relative, string $reason): void {
        error_log(sprintf('[dsgo-apps] media.publish skip app=%s path=%s reason=%s', $app_id, $relative, $reason));
    }
}
