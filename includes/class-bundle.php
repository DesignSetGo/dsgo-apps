<?php
/**
 * Filesystem operations for app bundles under uploads/dsgo-apps/{id}/.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class BundleError extends \RuntimeException {
    public function __construct(
        public readonly string $error_code,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class Bundle {

    public const ASSET_INDEX_FILENAME = '.dsgo-assets.json';

    public const ALLOWED_EXTENSIONS = [
        'html','htm','js','mjs','jsx','css','json','svg',
        'png','jpg','jpeg','gif','webp','avif',
        'woff','woff2','txt','md','map',
    ];
    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
    public const MAX_FILE_COUNT = 500;

    public static function dir_for(string $app_id): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'dsgo-apps/' . $app_id . '/';
    }

    public static function url_for(string $app_id): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . 'dsgo-apps/' . $app_id . '/';
    }

    public static function is_safe_zip_entry(string $path): bool {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }
        $segments = explode('/', $path);
        foreach ($segments as $seg) {
            if ($seg === '..' || $seg === '.' || $seg === '') {
                return false;
            }
            if (str_starts_with($seg, '.') && !str_starts_with($path, '.well-known/')) {
                return false;
            }
        }
        return true;
    }

    public static function is_allowed_extension(string $ext): bool {
        return in_array(strtolower($ext), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Walk a freshly extracted bundle and write a flat list of every
     * regular file path (bundle-relative, forward-slash) plus a hashed-asset
     * hint to a single JSON sidecar. Used by the renderer to answer
     * "is this path a bundle asset?" with a hash-set lookup instead of N
     * stat() syscalls per request.
     *
     * Format:
     * { "files": ["index.html", "_astro/foo.abc123.js", ...] }
     */
    public static function write_asset_index(string $bundle_dir): void {
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $bundle_dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $base_len = strlen(rtrim($bundle_dir, '/\\')) + 1;
        foreach ($iter as $info) {
            if (!$info->isFile()) continue;
            $abs = $info->getPathname();
            $rel = ltrim(substr($abs, $base_len), '/\\');
            // Don't list our own sidecar.
            if ($rel === self::ASSET_INDEX_FILENAME) continue;
            // Normalize Windows separators.
            $files[] = str_replace('\\', '/', $rel);
        }
        sort($files);
        $payload = wp_json_encode(['files' => $files]);
        if (is_string($payload)) {
            @file_put_contents(rtrim($bundle_dir, '/') . '/' . self::ASSET_INDEX_FILENAME, $payload);
        }
    }

    /**
     * Read the asset index sidecar produced by write_asset_index().
     * Returns null when the sidecar is missing (e.g., upgraded bundle from
     * an older installer); callers fall back to filesystem stat.
     *
     * Memoized within a single request because both InlineRenderer::resolve_asset
     * and rewrite_bundle_asset_paths read it for the same bundle on every hit.
     *
     * @return array<string, true>|null  Hash set of bundle-relative paths.
     */
    public static function load_asset_index(string $bundle_dir): ?array {
        static $cache = [];
        $path = rtrim($bundle_dir, '/') . '/' . self::ASSET_INDEX_FILENAME;
        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $cache[$path] = null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['files']) || !is_array($decoded['files'])) {
            return $cache[$path] = null;
        }
        $set = [];
        foreach ($decoded['files'] as $f) {
            if (is_string($f) && $f !== '') {
                $set[$f] = true;
            }
        }
        return $cache[$path] = $set;
    }

    public static function recursive_delete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::recursive_delete($path);
            } else {
                // Recursive delete inside the bundle install dir; WP_Filesystem
                // requires an FTP/SSH context that isn't available during REST.
                @unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
        @rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    /**
     * Materialize a files-map (path => content) into a temp directory and
     * return its path. Caller is responsible for cleanup via recursive_delete().
     *
     * Used by the harness (CLI + REST) to turn an LLM-produced envelope into
     * a bundle the existing zip + installer code can consume.
     *
     * @param array<string,string> $files
     * @throws BundleError on unsafe paths, write failures, or post-extract validation failure.
     */
    /**
     * Overwrite top-level fields of `dsgo-app.json` inside an existing zip.
     *
     * Used by the redeploy path to rebrand a draft as the id+version-bumped
     * variant of an already-installed app. The Manifest validator reads
     * `$raw['id']` and `$raw['version']` at the top level, so those are the
     * fields callers typically pass; any other top-level keys are likewise
     * supported. Nested `app.*` keys are mirrored when present so legacy
     * consumers stay coherent.
     *
     * @param string              $zip_path Absolute path to a writable zip.
     * @param array<string,mixed> $fields   Top-level fields to overwrite, e.g. ['id' => 'foo', 'version' => '1.0.4'].
     * @throws BundleError on zip / manifest parse failure.
     */
    public static function patch_manifest_fields(string $zip_path, array $fields): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new BundleError('invalid_zip', sprintf('cannot open %s for patch', $zip_path));
        }
        $raw = $zip->getFromName('dsgo-app.json');
        if ($raw === false) {
            $zip->close();
            throw new BundleError('missing_manifest', 'dsgo-app.json missing inside zip');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $zip->close();
            throw new BundleError('invalid_manifest', 'dsgo-app.json is not valid JSON');
        }
        foreach ($fields as $k => $v) {
            $decoded[$k] = $v;
            // Mirror into the optional nested `app` block if it exists, so
            // legacy consumers stay in sync with the canonical top-level value.
            if (isset($decoded['app']) && is_array($decoded['app']) && array_key_exists($k, $decoded['app'])) {
                $decoded['app'][$k] = $v;
            }
        }
        $patched = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $zip->deleteName('dsgo-app.json');
        if (!$zip->addFromString('dsgo-app.json', (string) $patched)) {
            $zip->close();
            throw new BundleError('write_failed', 'could not write patched manifest');
        }
        $zip->close();
    }

    public static function from_files_map(array $files, Manifest $manifest): string
    {
        // wp_tempnam() lives in wp-admin/includes/file.php which isn't loaded
        // during REST requests — use the native function instead.
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-harness-bundle-');
        // Per-request scratch dir under sys_get_temp_dir(); WP_Filesystem can't
        // operate on the system temp dir without an FTP/SSH context.
        // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (file_exists($tmp)) {
            unlink($tmp);
        }
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new BundleError('tmp_unwritable', sprintf('could not create %s', $tmp));
        }
        foreach ($files as $rel => $content) {
            if (!self::is_safe_zip_entry($rel)) {
                throw new BundleError('unsafe_path', sprintf('rejected path: %s', $rel));
            }
            $abs = $tmp . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $written = file_put_contents($abs, $content);
            if ($written === false) {
                throw new BundleError('write_failed', sprintf('could not write %s', $rel));
            }
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        // Run the existing post-extract validator for parity with the installer path.
        self::validate_post_extract($tmp, $manifest);
        return $tmp;
    }

    public static function validate_post_extract(string $bundle_dir, Manifest $manifest): void {
        if ($manifest->isolation !== 'inline') {
            return;
        }

        foreach ($manifest->routes as $route) {
            $abs = $bundle_dir . '/' . $route['file'];
            if (!is_file($abs)) {
                throw new BundleError(
                    'missing_route_file',
                    sprintf('route %s references missing file: %s', $route['path'], $route['file']),
                );
            }
            $html = file_get_contents($abs);
            if ($html === false) {
                throw new BundleError(
                    'unreadable_route_file',
                    sprintf('cannot read route file: %s', $route['file']),
                );
            }
            // Match the renderer's pre-sanitize rewrite so the install-time
            // check sees the same shape we'd actually serve.
            $html = InlineRenderer::rewrite_bundle_asset_paths($html, $bundle_dir, $manifest);
            try {
                HtmlSanitizer::sanitize($html, [
                    'nonce'              => 'INSTALL-CHECK',
                    'allow_root_paths'   => $manifest->mount_mode === MountMode::Root,
                    'allow_url_prefix'   => $manifest->mount_mode === MountMode::Root
                        ? null
                        : Settings::app_base_path($manifest->id),
                    'stylesheet_origins' => $manifest->csp['style_src'] ?? [],
                    'script_origins'     => $manifest->csp['script_src'] ?? [],
                    'embed_origins'      => $manifest->embeds,
                ]);
            } catch (HtmlSanitizerError $e) {
                throw new BundleError(
                    'sanitizer_violation',
                    sprintf('route %s (%s): %s', $route['path'], $route['file'], $e->getMessage()),
                );
            }

            if (isset($route['dataset']) && is_array($route['dataset'])) {
                self::validate_route_dataset($bundle_dir, $route);
            }
        }
    }

    private static function validate_route_dataset(string $bundle_dir, array $route): void {
        $source = $route['dataset']['source'];
        $id_field = $route['dataset']['id_field'];
        $abs = $bundle_dir . '/' . $source;
        if (!is_file($abs)) {
            throw new BundleError(
                'dataset_missing',
                sprintf('route %s: dataset file %s not found in bundle', $route['path'], $source),
            );
        }
        $raw = file_get_contents($abs);
        if ($raw === false) {
            throw new BundleError(
                'dataset_missing',
                sprintf('route %s: dataset file %s is unreadable', $route['path'], $source),
            );
        }
        $parsed = json_decode($raw, true);
        if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new BundleError(
                'dataset_invalid_json',
                sprintf('route %s: dataset %s — %s', $route['path'], $source, json_last_error_msg()),
            );
        }
        if (!is_array($parsed) || !array_is_list($parsed)) {
            throw new BundleError(
                'dataset_not_array',
                sprintf('route %s: dataset %s top level must be a JSON array', $route['path'], $source),
            );
        }
        if (count($parsed) > 500) {
            throw new BundleError(
                'dataset_too_large',
                sprintf('route %s: dataset %s has %d entries (max 500)', $route['path'], $source, count($parsed)),
            );
        }
        $seen_ids = [];
        foreach ($parsed as $i => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new BundleError(
                    'dataset_entry_not_object',
                    sprintf('route %s: dataset %s entry [%d] must be an object', $route['path'], $source, $i),
                );
            }
            if (!array_key_exists($id_field, $entry) || $entry[$id_field] === null) {
                throw new BundleError(
                    'dataset_missing_id',
                    sprintf('route %s: dataset %s entry [%d] missing id_field "%s"', $route['path'], $source, $i, $id_field),
                );
            }
            $id_value = $entry[$id_field];
            if (!is_string($id_value) && !is_int($id_value) && !is_float($id_value)) {
                throw new BundleError(
                    'dataset_id_not_scalar',
                    sprintf('route %s: dataset %s entry [%d] id_field "%s" must be a string or number', $route['path'], $source, $i, $id_field),
                );
            }
            $id_str = (string) $id_value;
            if ($id_str === '.' || $id_str === '..') {
                throw new BundleError(
                    'dataset_id_not_url_safe',
                    sprintf('route %s: dataset %s entry [%d] id "%s" is not allowed', $route['path'], $source, $i, $id_str),
                );
            }
            if (!preg_match('/^[a-zA-Z0-9_~-]+$/', $id_str)) {
                throw new BundleError(
                    'dataset_id_not_url_safe',
                    sprintf('route %s: dataset %s entry [%d] id "%s" must match ^[a-zA-Z0-9_~-]+$', $route['path'], $source, $i, $id_str),
                );
            }
            if (isset($seen_ids[$id_str])) {
                throw new BundleError(
                    'dataset_duplicate_id',
                    sprintf('route %s: dataset %s has duplicate id "%s"', $route['path'], $source, $id_str),
                );
            }
            $seen_ids[$id_str] = true;
        }
    }
}
