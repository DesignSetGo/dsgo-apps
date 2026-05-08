<?php
/**
 * Bundle installer — zip → validate → extract → register post.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final readonly class InstallResult {
    public function __construct(
        public string $app_id,
        public int $post_id,
        public string $url,
    ) {}
}

final class InstallerError extends \RuntimeException {
    public readonly string $error_code;
    public readonly string $bare_message;
    public function __construct(string $code, string $message) {
        $this->error_code   = $code;
        $this->bare_message = $message;
        // PHP exception message keeps the code prefix so logs/stack traces
        // remain self-describing; REST responses send `bare_message` to
        // avoid the client doubling the prefix.
        parent::__construct(sprintf('%s: %s', $code, $message));
    }
}

final class Installer {

    public static function install(string $zip_path, int $user_id): InstallResult {
        if (!user_can($user_id, 'manage_options')) {
            throw new InstallerError('forbidden', 'caller lacks manage_options');
        }
        if (!file_exists($zip_path)) {
            throw new InstallerError('not_found', 'zip file does not exist');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new InstallerError('invalid_zip', 'cannot open zip');
        }

        $manifest_raw = $zip->getFromName('dsgo-app.json');
        if ($manifest_raw === false) {
            $zip->close();
            throw new InstallerError('missing_manifest', 'dsgo-app.json not found at zip root');
        }
        $decoded = json_decode($manifest_raw, true);
        if (!is_array($decoded)) {
            $zip->close();
            throw new InstallerError('invalid_manifest', 'dsgo-app.json is not valid JSON');
        }

        try {
            $manifest = Manifest::validate($decoded);
        } catch (ManifestError $e) {
            $zip->close();
            throw new InstallerError('invalid_manifest', $e->getMessage());
        }

        // Reject a second root-mount install. At most one app per site may
        // own the site root; re-installing the *same* root app is fine
        // (treated as an update).
        if ($manifest->mount_mode === MountMode::Root) {
            $existing_root = Settings::get_root_app_id();
            if ($existing_root !== null && $existing_root !== $manifest->id) {
                $zip->close();
                throw new InstallerError(
                    'root_mount_conflict',
                    sprintf(
                        'app "%s" is already mounted at the site root; only one root-mounted app is allowed at a time',
                        $existing_root,
                    ),
                );
            }
        }

        self::validate_zip_contents($zip);

        // Acquire a per-app install lock. Two CLI deploys (or a CI retry that
        // fires twice) for the same slug arriving within seconds of each other
        // would otherwise interleave the stash/extract/update steps and leave
        // the bundle dir half-written. add_option is the WP equivalent of an
        // atomic INSERT — first caller wins.
        $lock_acquired = self::acquire_install_lock($manifest->id);
        if (!$lock_acquired) {
            $zip->close();
            throw new InstallerError(
                'install_in_progress',
                sprintf('another install for "%s" is already in progress; retry in a moment', $manifest->id),
            );
        }

        $bundle_dir = Bundle::dir_for($manifest->id);
        $rollback   = self::stash_existing($bundle_dir);
        $zip_closed = false;

        try {
            if (!is_dir($bundle_dir) && !wp_mkdir_p($bundle_dir)) {
                throw new InstallerError('fs_error', sprintf('cannot create bundle dir at %s', $bundle_dir));
            }
            if (!$zip->extractTo($bundle_dir)) {
                throw new InstallerError('fs_error', 'extraction failed');
            }
            $zip->close();
            $zip_closed = true;

            try {
                Bundle::validate_post_extract($bundle_dir, $manifest);
            } catch (BundleError $e) {
                $code = $e->error_code === 'sanitizer_violation' ? 'invalid_route_html' : 'missing_route_file';
                throw new InstallerError($code, $e->getMessage());
            }

            // Build the on-disk asset index the renderer uses to answer
            // "is /foo/bar.js a bundle file?" without stat()ing per request.
            Bundle::write_asset_index($bundle_dir);

            // Inline-mode apps serve CSP via HTTP headers per-request (InlineRenderer)
            // and receive the bridge-client script at render time — skip static injection.
            if ($manifest->isolation !== 'inline') {
                self::inject_bridge_client($bundle_dir . $manifest->entry, $manifest->id, $manifest);
                // Iframe-mode bundles run inside `sandbox="allow-scripts"` (opaque
                // origin), so any `<script type="module">` in the entry — typical
                // for Astro / Next / Vite output — is fetched through CORS.
                // Drop a small `.htaccess` next to the bundle so Apache returns
                // `Access-Control-Allow-Origin: *` for asset requests, which is
                // what the spec requires for null-origin module fetches.
                self::write_bundle_cors_htaccess($bundle_dir);
            }

            $existing = get_page_by_path($manifest->id, OBJECT, PostType::SLUG);
            $post_arr = [
                'post_type'   => PostType::SLUG,
                'post_status' => 'publish',
                'post_name'   => $manifest->id,
                'post_title'  => $manifest->name,
            ];
            if ($existing instanceof \WP_Post) {
                $post_arr['ID'] = $existing->ID;
                $post_id = wp_update_post($post_arr, true);
            } else {
                $post_id = wp_insert_post($post_arr, true);
            }
            if (is_wp_error($post_id)) {
                throw new InstallerError('post_error', $post_id->get_error_message());
            }

            update_post_meta($post_id, 'dsgo_apps_manifest', $manifest->to_array());
            update_post_meta($post_id, 'dsgo_apps_bundle_path', $bundle_dir);
            update_post_meta($post_id, 'dsgo_apps_installed_version', $manifest->version);
            // Dedicated, indexable meta keys so frontend dispatchers can
            // look them up without unserializing the full manifest array.
            update_post_meta($post_id, 'dsgo_apps_mount_mode', $manifest->mount_mode->value);
            update_post_meta($post_id, 'dsgo_apps_isolation', $manifest->isolation);

            if ($rollback !== null) {
                Bundle::recursive_delete($rollback);
            }

            // Refresh the cached single-root-app id so the dispatcher and
            // sitemap stay coherent across installs that flip mount modes.
            // Note: no flush_rewrite_rules() here — the only rule we register
            // is parameterized by `dsgo_apps_url_prefix`, which doesn't
            // change at install time. Settings::on_prefix_changed handles
            // flushing when the operator edits the prefix in admin.
            Settings::refresh_root_app_id();
            InlineRenderer::bump_cache_version($manifest->id);
            SitemapProvider::invalidate_cache();
            AbilitiesPublisher::register_for_app($manifest);

            self::release_install_lock($manifest->id);

            return new InstallResult(
                app_id: $manifest->id,
                post_id: (int) $post_id,
                url: home_url(self::install_url_path($manifest)),
            );
        } catch (\Throwable $e) {
            if (!$zip_closed) { @$zip->close(); }
            Bundle::recursive_delete($bundle_dir);
            if ($rollback !== null) {
                @rename($rollback, $bundle_dir);
            }
            self::release_install_lock($manifest->id);
            if ($e instanceof InstallerError) {
                throw $e;
            }
            throw new InstallerError('install_failed', $e->getMessage());
        }
    }

    /** Atomic compare-and-set: returns true iff this caller owns the lock. */
    private static function acquire_install_lock(string $app_id): bool {
        $key = self::lock_option_key($app_id);
        // Clear a stale lock from a crashed install (older than the timeout).
        $existing = get_option($key);
        if (is_array($existing) && isset($existing['acquired_at'])) {
            if (((int) $existing['acquired_at']) + self::LOCK_TIMEOUT_SECONDS < time()) {
                delete_option($key);
            }
        }
        return add_option($key, ['acquired_at' => time(), 'pid' => getmypid()], '', 'no');
    }

    private static function release_install_lock(string $app_id): void {
        delete_option(self::lock_option_key($app_id));
    }

    private static function lock_option_key(string $app_id): string {
        // Bound to 64 chars (WP option key limit) — slug is already capped at 64.
        return 'dsgo_apps_install_lock_' . substr($app_id, 0, 32);
    }

    private const LOCK_TIMEOUT_SECONDS = 120;

    private static function install_url_path(Manifest $manifest): string {
        if ($manifest->mount_mode === MountMode::Root) {
            return '/';
        }
        return '/' . Settings::get_url_prefix() . '/' . $manifest->id;
    }

    private static function validate_zip_contents(\ZipArchive $zip): void {
        $count = 0;
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) { continue; }
            $name = $stat['name'];
            if (str_ends_with($name, '/')) { continue; }
            if (!Bundle::is_safe_zip_entry($name)) {
                throw new InstallerError('unsafe_path', sprintf('zip entry "%s" rejected', $name));
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!Bundle::is_allowed_extension($ext)) {
                throw new InstallerError('forbidden_extension', sprintf('zip entry "%s" has disallowed extension', $name));
            }
            $total += (int) $stat['size'];
            $count++;
        }
        if ($count > Bundle::MAX_FILE_COUNT) {
            throw new InstallerError('too_many_files', sprintf('bundle has %d files (max %d)', $count, Bundle::MAX_FILE_COUNT));
        }
        if ($total > Bundle::MAX_TOTAL_BYTES) {
            throw new InstallerError('bundle_too_large', sprintf('bundle is %d bytes (max %d)', $total, Bundle::MAX_TOTAL_BYTES));
        }
    }

    private static function stash_existing(string $bundle_dir): ?string {
        if (!is_dir($bundle_dir)) {
            return null;
        }
        $stash = rtrim($bundle_dir, '/') . '.previous-' . uniqid();
        if (!@rename($bundle_dir, $stash)) {
            throw new InstallerError('fs_error', 'cannot stash existing bundle');
        }
        return $stash;
    }

    private static function write_bundle_cors_htaccess(string $bundle_dir): void {
        $contents = "<IfModule mod_headers.c>\n"
            . "  Header set Access-Control-Allow-Origin \"*\"\n"
            . "  Header set Cross-Origin-Resource-Policy \"cross-origin\"\n"
            . "</IfModule>\n";
        @file_put_contents(rtrim($bundle_dir, '/') . '/.htaccess', $contents);
    }

    private static function inject_bridge_client(string $entry_path, string $app_id, Manifest $manifest): void {
        $html = file_get_contents($entry_path);
        if ($html === false) {
            throw new InstallerError('invalid_entry_html', 'cannot read entry html');
        }
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        if (!$loaded) {
            throw new InstallerError('invalid_entry_html', 'entry html could not be parsed; ensure it has <html> and <head>');
        }
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head === null) {
            throw new InstallerError('invalid_entry_html', 'entry html has no <head>; add <html><head></head><body>...</body></html>');
        }

        $csp = self::build_csp($app_id, $manifest);
        $meta = $doc->createElement('meta');
        $meta->setAttribute('http-equiv', 'Content-Security-Policy');
        $meta->setAttribute('content', $csp);

        $script = $doc->createElement('script');
        $script->setAttribute('src', plugins_url('assets/bridge-client.js', DSGO_APPS_FILE));
        $script->setAttribute('defer', '');

        $first = $head->firstChild;
        if ($first !== null) {
            $head->insertBefore($meta, $first);
            $head->insertBefore($script, $meta);
        } else {
            $head->appendChild($meta);
            $head->appendChild($script);
        }

        $serialized = $doc->saveHTML();
        if ($serialized === false) {
            throw new InstallerError('fs_error', 'cannot serialize rewritten html');
        }
        // Strip DOMDocument UTF-8 XML PI hack inserted above; it may end with a bare > or question-mark-gt.
        $serialized = preg_replace('#<\?xml[^>]*>\s*#', '', $serialized);
        $rewritten  = '<!doctype html>' . "\n" . $serialized;
        if (file_put_contents($entry_path, $rewritten) === false) {
            throw new InstallerError('fs_error', 'cannot write rewritten entry html');
        }
    }

    private static function build_csp(string $app_id, Manifest $manifest): string {
        $bundle_url  = rtrim(Bundle::url_for($app_id), '/') . '/';
        $assets_url  = rtrim(plugins_url('assets/', DSGO_APPS_FILE), '/') . '/';
        $external    = $manifest->external_origins;
        $connect_src = $external !== [] ? implode(' ', $external) : "'none'";
        return implode('; ', [
            "default-src 'none'",
            "script-src $assets_url $bundle_url 'unsafe-inline'",
            "style-src $bundle_url 'unsafe-inline'",
            "img-src $bundle_url data: blob:",
            "font-src $bundle_url",
            "connect-src $connect_src",
            "frame-ancestors 'self'",
        ]);
    }
}
