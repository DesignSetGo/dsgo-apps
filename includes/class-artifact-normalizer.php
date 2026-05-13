<?php
/**
 * Wraps a single HTML file (typically a downloaded Claude Artifact) into a
 * minimal iframe-mode bundle that {@see Installer::install()} can ingest.
 *
 * The original v1 design fetched a URL server-side, but Claude artifact share
 * URLs are Cloudflare-challenged AND return a Next.js SPA shell with relative
 * asset paths to claudeusercontent.com — neither shape is usable as a
 * self-contained sandboxed bundle. The HTML-upload flow trades a click ("Save
 * page as…" or use Claude's download) for a reliable, origin-free import.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class ArtifactNormalizerError extends \RuntimeException {
    public readonly string $error_code;
    public readonly string $bare_message;
    public function __construct(string $code, string $message) {
        $this->error_code   = $code;
        $this->bare_message = $message;
        parent::__construct(sprintf('%s: %s', $code, $message));
    }
}

final class ArtifactNormalizer {

    private const ID_PATTERN     = '/^[a-z][a-z0-9-]{2,63}$/';
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';
    private const DEFAULT_VERSION = '0.1.0';

    /**
     * Validate the inputs, then write {index.html, dsgo-app.json} into a temp
     * dir, zip them, and return the zip path. Caller is responsible for
     * unlinking the zip + its parent directory once Installer::install runs.
     *
     * @throws ArtifactNormalizerError on validation failure (the staging dir
     *   is cleaned before throwing).
     */
    public static function pack_html(
        string $body,
        string $id,
        ?string $name,
        ?string $version,
    ): string {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new ArtifactNormalizerError('invalid_id', sprintf('id must match %s (got "%s")', self::ID_PATTERN, $id));
        }
        $version = $version ?? self::DEFAULT_VERSION;
        if (!preg_match(self::SEMVER_PATTERN, $version)) {
            throw new ArtifactNormalizerError('invalid_version', sprintf('version must be valid SemVer (got "%s")', $version));
        }
        if ($body === '') {
            throw new ArtifactNormalizerError('empty_html', 'HTML body is empty');
        }
        $max_bytes = Bundle::max_total_bytes();
        if (strlen($body) > $max_bytes) {
            throw new ArtifactNormalizerError(
                'artifact_too_large',
                sprintf('HTML body too large (%d bytes > %d)', strlen($body), $max_bytes),
            );
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new ArtifactNormalizerError('invalid_html', 'HTML body is not valid UTF-8');
        }

        $work_dir = self::make_work_dir();
        try {
            $html_path = $work_dir . '/index.html';
            $manifest_path = $work_dir . '/dsgo-app.json';
            $zip_path = $work_dir . '/bundle.zip';

            file_put_contents($html_path, $body);
            file_put_contents($manifest_path, self::synthesize_manifest($id, $name, $version));

            $zip = new \ZipArchive();
            if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new ArtifactNormalizerError('zip_failed', 'could not open zip for writing');
            }
            $zip->addFile($html_path, 'index.html');
            $zip->addFile($manifest_path, 'dsgo-app.json');
            $zip->close();

            // Drop staging files; only the zip needs to survive for the caller
            // to hand off to Installer::install. WP_Filesystem can't run reliably
            // here (REST has no FTP/SSH context).
            @unlink($html_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($manifest_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            return $zip_path;
        } catch (\Throwable $e) {
            self::recursive_delete($work_dir);
            throw $e;
        }
    }

    private static function synthesize_manifest(string $id, ?string $name, string $version, string $entry = 'index.html'): string {
        return (string) wp_json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => $name ?? $id,
            'version'          => $version,
            'entry'            => $entry,
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Wrap a multi-file static bundle (e.g. a Claude Design export — home.html
     * + src/*.jsx + styles.css + uploads/) into an iframe-mode app.
     *
     * The input zip is expected to contain at least one HTML file at the
     * archive root and no `dsgo-app.json`. Files with disallowed extensions
     * (see {@see Bundle::ALLOWED_EXTENSIONS}) are silently skipped so authors
     * don't have to scrub dotfiles or notes by hand. Safe paths are enforced
     * with the same rules {@see Installer::install} applies post-extract.
     *
     * Entry resolution prefers index.html → home.html → first `*.html` at the
     * archive root.
     *
     * @throws ArtifactNormalizerError on validation failure.
     */
    public static function pack_static_zip(
        string $input_zip_path,
        string $id,
        ?string $name,
        ?string $version,
    ): string {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new ArtifactNormalizerError('invalid_id', sprintf('id must match %s (got "%s")', self::ID_PATTERN, $id));
        }
        $version = $version ?? self::DEFAULT_VERSION;
        if (!preg_match(self::SEMVER_PATTERN, $version)) {
            throw new ArtifactNormalizerError('invalid_version', sprintf('version must be valid SemVer (got "%s")', $version));
        }

        $input = new \ZipArchive();
        if ($input->open($input_zip_path) !== true) {
            throw new ArtifactNormalizerError('invalid_zip', 'could not open uploaded zip');
        }

        try {
            // Peek for a manifest first — if the user uploaded a manifested
            // bundle to the static-import path, fail loudly so they use the
            // regular bundle tab and don't silently get our synthesized one.
            if ($input->getFromName('dsgo-app.json') !== false) {
                throw new ArtifactNormalizerError(
                    'manifest_present',
                    'zip already contains dsgo-app.json — use the bundle upload path instead',
                );
            }

            $max_bytes = Bundle::max_total_bytes();
            $entry_candidates = [];
            $kept_entries     = [];
            $total_bytes      = 0;
            for ($i = 0; $i < $input->numFiles; $i++) {
                $stat = $input->statIndex($i);
                if ($stat === false) continue;
                $name_in_zip = (string) $stat['name'];
                if ($name_in_zip === '' || str_ends_with($name_in_zip, '/')) continue; // directory entry
                if (!Bundle::is_safe_zip_entry($name_in_zip)) {
                    throw new ArtifactNormalizerError(
                        'unsafe_path',
                        sprintf('zip contains unsafe path "%s"', $name_in_zip),
                    );
                }
                $ext = strtolower((string) pathinfo($name_in_zip, PATHINFO_EXTENSION));
                if ($ext === '' || !Bundle::is_allowed_extension($ext)) {
                    // Silently skip auxiliary files (e.g. .DS_Store residue
                    // wouldn't even pass is_safe_zip_entry; this catches stray
                    // .ts/.scss/etc that authors haven't built).
                    continue;
                }
                $kept_entries[] = $name_in_zip;
                $total_bytes   += (int) $stat['size'];
                if ($total_bytes > $max_bytes) {
                    throw new ArtifactNormalizerError(
                        'artifact_too_large',
                        sprintf('bundle exceeds %d bytes uncompressed', $max_bytes),
                    );
                }
                if (count($kept_entries) > Bundle::MAX_FILE_COUNT) {
                    throw new ArtifactNormalizerError(
                        'too_many_files',
                        sprintf('bundle exceeds %d files', Bundle::MAX_FILE_COUNT),
                    );
                }
                if (!str_contains($name_in_zip, '/') && ($ext === 'html' || $ext === 'htm')) {
                    $entry_candidates[] = $name_in_zip;
                }
            }

            if ($kept_entries === []) {
                throw new ArtifactNormalizerError('empty_bundle', 'zip contained no files with supported extensions');
            }

            $entry = self::pick_entry($entry_candidates);
            if ($entry === null) {
                throw new ArtifactNormalizerError(
                    'missing_entry_html',
                    'no .html file found at the zip root (expected index.html or home.html)',
                );
            }

            $work_dir = self::make_work_dir();
            $out_zip_path = $work_dir . '/bundle.zip';

            $out = new \ZipArchive();
            if ($out->open($out_zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                self::recursive_delete($work_dir);
                throw new ArtifactNormalizerError('zip_failed', 'could not open output zip for writing');
            }

            try {
                foreach ($kept_entries as $rel) {
                    $bytes = $input->getFromName($rel);
                    if ($bytes === false) continue;
                    $out->addFromString($rel, $bytes);
                }
                $out->addFromString(
                    'dsgo-app.json',
                    self::synthesize_manifest($id, $name, $version, $entry),
                );
            } finally {
                $out->close();
            }

            return $out_zip_path;
        } finally {
            $input->close();
        }
    }

    /** @param string[] $candidates */
    private static function pick_entry(array $candidates): ?string {
        if ($candidates === []) return null;
        if (in_array('index.html', $candidates, true)) return 'index.html';
        if (in_array('index.htm', $candidates, true))  return 'index.htm';
        if (in_array('home.html', $candidates, true))  return 'home.html';
        if (in_array('home.htm', $candidates, true))   return 'home.htm';
        return $candidates[0];
    }

    private static function make_work_dir(): string {
        $base = get_temp_dir();
        for ($i = 0; $i < 5; $i++) {
            $candidate = rtrim($base, '/\\') . '/dsgo-artifact-' . wp_generate_password(8, false, false);
            // Per-request scratch dir under get_temp_dir(); WP_Filesystem can't
            // run without an FTP/SSH context during REST.
            if (mkdir($candidate, 0700, false)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                return $candidate;
            }
        }
        throw new ArtifactNormalizerError('fs_error', 'could not create temp working directory');
    }

    // Recursive delete operates on the per-request artifact temp dir we just
    // created above. WP_Filesystem can't be relied on here (REST context).
    // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    private static function recursive_delete(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::recursive_delete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
