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
}
