<?php
/**
 * Bundle installer — zip → validate → extract → register post.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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

/**
 * Returned by Installer::preview() — captures everything the install dialog
 * needs to show consent for an upcoming install/update without performing it.
 */
final readonly class PreviewResult {
    /**
     * @param string[]  $buckets             Bucket values activated by the manifest.
     * @param ?string[] $previously_approved Bucket values approved at the previous install, or null on first install.
     * @param string[]  $new_buckets         Buckets in $buckets but not in $previously_approved (empty on first install).
     * @param string[]  $removed_buckets     Buckets in $previously_approved but not in $buckets (empty on first install).
     * @param string    $rendered_html       Pre-rendered HTML for the consent panel body.
     */
    public function __construct(
        public string $app_id,
        public string $name,
        public string $version,
        public bool   $is_update,
        public array  $buckets,
        public ?array $previously_approved,
        public array  $new_buckets,
        public array  $removed_buckets,
        public string $rendered_html,
    ) {}
}

final class Installer {

    /**
     * Validate a zip and compute bucket activation without installing. The
     * REST `POST /apps/preview` endpoint uses this to drive the install
     * dialog's consent panel before the user re-uploads the same zip to
     * `POST /apps` for the actual install.
     *
     * Throws the same InstallerError shape as install() for any validation
     * failure, so callers can render error messages with one branch.
     */
    public static function preview(string $zip_path, int $user_id): PreviewResult {
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
        $zip->close();
        if ($manifest_raw === false) {
            throw new InstallerError('missing_manifest', 'dsgo-app.json not found at zip root');
        }
        $decoded = json_decode($manifest_raw, true);
        if (!is_array($decoded)) {
            throw new InstallerError('invalid_manifest', 'dsgo-app.json is not valid JSON');
        }
        try {
            $manifest = Manifest::validate($decoded);
        } catch (ManifestError $e) {
            throw new InstallerError('invalid_manifest', $e->getMessage());
        }

        $existing            = get_page_by_path($manifest->id, OBJECT, PostType::SLUG);
        $is_update           = $existing instanceof \WP_Post;
        $previously_approved = null;
        if ($is_update) {
            $stored = get_post_meta($existing->ID, 'dsgo_apps_active_buckets', true);
            // Treat "missing" (empty string from get_post_meta) and "previously
            // installed without bucket meta" the same way: empty array, not null.
            // null is reserved for "first install, never seen this app."
            $previously_approved = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
        }

        // Compute active buckets once and pass to render_consent_html — saves
        // a redundant Bucket::active_for() call inside the renderer.
        $active_buckets  = Bucket::active_for($manifest);
        $buckets         = array_map(fn (Bucket $b) => $b->value, $active_buckets);
        $new_buckets     = $previously_approved === null
            ? []
            : array_values(array_diff($buckets, $previously_approved));
        $removed_buckets = $previously_approved === null
            ? []
            : array_values(array_diff($previously_approved, $buckets));

        return new PreviewResult(
            app_id: $manifest->id,
            name: $manifest->name,
            version: $manifest->version,
            is_update: $is_update,
            buckets: $buckets,
            previously_approved: $previously_approved,
            new_buckets: $new_buckets,
            removed_buckets: $removed_buckets,
            rendered_html: self::render_consent_html($manifest, $previously_approved, $active_buckets),
        );
    }

    /**
     * Render the HTML body for the install-dialog consent panel. Includes
     * one row per active bucket (via Bucket_Renderer), the diff highlight
     * for new permissions, the "Previously approved (unchanged)" line, the
     * "Removed" section, and the passive storage footer.
     *
     * Callers that have already computed the active bucket set can pass it
     * via $active_buckets to avoid recomputing inside this method. When
     * null, the method computes it.
     *
     * @param Bucket[]|null $active_buckets
     */
    private static function render_consent_html(Manifest $manifest, ?array $previously_approved, ?array $active_buckets = null): string {
        $active_buckets ??= Bucket::active_for($manifest);
        $active_values   = array_map(fn (Bucket $b) => $b->value, $active_buckets);

        $out = '<div class="dsgo-install-dialog">';

        // Active bucket rows. New buckets get the dsgo-bucket--new marker.
        foreach ($active_buckets as $bucket) {
            $out .= Bucket_Renderer::render_row($bucket, $manifest, $previously_approved);
        }

        // Previously approved + unchanged collapse (update flow only).
        if ($previously_approved !== null) {
            $unchanged = array_values(array_intersect($previously_approved, $active_values));
            if ($unchanged !== []) {
                $out .= '<p class="dsgo-install-dialog__unchanged">'
                      . esc_html__('Previously approved (unchanged):', 'designsetgo-apps') . ' '
                      . esc_html(implode(', ', $unchanged))
                      . '</p>';
            }
            $removed = array_values(array_diff($previously_approved, $active_values));
            if ($removed !== []) {
                $out .= '<section class="dsgo-install-dialog__removed">';
                $out .= '<h4>' . esc_html__('Removed (no action required)', 'designsetgo-apps') . '</h4>';
                $out .= '<ul>';
                foreach ($removed as $r) {
                    $out .= '<li><code>' . esc_html($r) . '</code></li>';
                }
                $out .= '</ul></section>';
            }
        }

        // Passive storage footer (always shown — storage has no bucket).
        $out .= '<p class="dsgo-install-dialog__storage-note">'
              . esc_html__('This app uses per-app and per-user storage to persist state.', 'designsetgo-apps')
              . '</p>';

        $out .= '</div>';
        return $out;
    }

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

        // No app-count cap. Pro-gated runtime features (cron, webhooks,
        // abilities.publishes, dynamic routes) are skipped at registration
        // time on free sites; install itself always succeeds.
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
            // Capture the prior manifest BEFORE update_post_meta
            // overwrites it. CronScheduler::reconcile diffs prev vs new
            // to leave unchanged jobs intact (re-scheduling resets the
            // next-fire timer, which we don't want on every save).
            $prev_manifest = null;
            if ($existing instanceof \WP_Post) {
                $prev_raw = get_post_meta($existing->ID, 'dsgo_apps_manifest', true);
                if (is_array($prev_raw)) {
                    try {
                        $prev_manifest = Manifest::from_array_unchecked($prev_raw);
                    } catch (\Throwable) {
                        // Best-effort — a malformed stored manifest just
                        // means we reconcile against null, which results
                        // in fresh scheduling. No regression vs the
                        // pre-Task 15 world.
                    }
                }
            }
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
            // The bucket set this manifest activates at install time. The
            // name is deliberately `active_buckets` (not `approved_buckets`)
            // because v1 install does NOT enforce consent: any admin can
            // POST a bundle directly to /apps and bypass the consent panel.
            // The value reflects what's CURRENTLY active per the manifest,
            // not what the admin clicked "Install" on. Diff-on-update uses
            // it as the baseline for "what was here before this install."
            // If we add real consent enforcement later (e.g. server-side
            // verification that the bundle's active set matches an
            // approved_buckets[] form field, rejecting strict supersets),
            // a separate `dsgo_apps_approved_buckets` key can land then.
            // Always written (even when empty) so absence never has to be
            // distinguished from "no buckets activated."
            $active_buckets = array_map(
                fn (Bucket $b) => $b->value,
                Bucket::active_for($manifest),
            );
            update_post_meta($post_id, 'dsgo_apps_active_buckets', $active_buckets);

            // Vault reconciliation on update: drop encrypted values whose
            // alias was REMOVED from the manifest's secrets[] block since
            // the previous install. Storage values would otherwise linger
            // indefinitely — bad on its own (defense in depth: don't keep
            // an encrypted credential we no longer have a use for) and
            // worse if the alias is later re-introduced and reused for a
            // different upstream. We don't touch values for aliases that
            // are still declared; admins keep whatever they entered.
            //
            // Runs on both first install (no-op — vault is empty) and on
            // update; cheap on both because list_set_aliases doesn't
            // decrypt and the diff is over short string arrays.
            if (Secret_Vault::is_available()) {
                self::reconcile_vault_against_manifest($manifest);
            }

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
            MediaPublisher::publish_for_app($manifest, $bundle_dir);

            // Cron + webhook surface activation (Task 15 of the cron+webhooks plan).
            //
            // CronScheduler::reconcile diffs prev vs new and only touches
            // jobs whose schedule/time/day_of_week changed — unchanged
            // jobs keep their next-fire timer, so re-installs of the same
            // manifest don't reset cron clocks.
            CronScheduler::reconcile($manifest->id, $manifest, $prev_manifest);
            // WebhookRouter::register is a no-op when the Pro gate is
            // closed (enforcement lives inside the router). When open,
            // newly added endpoints become callable on the next REST
            // request without waiting for rest_api_init's full sweep.
            WebhookRouter::register($manifest->id, $manifest->webhook_endpoints());

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
                // Roll back to the stashed prior install. WP_Filesystem::move()
                // requires an FTP/SSH context that isn't available during REST.
                @rename($rollback, $bundle_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            }
            self::release_install_lock($manifest->id);
            if ($e instanceof InstallerError) {
                throw $e;
            }
            throw new InstallerError('install_failed', $e->getMessage());
        }
    }

    /**
     * Maximum published apps the Lite plugin will allow on this site, or
     * null if the cap is disabled. Pro filters this to null when a Pro
     * license is active (see `Pro_Plugin::maybe_lift_cap`).
     *
     * Filter: `dsgo_apps_lite_app_cap` — return null/false to disable
     * enforcement; return a positive integer to set a different cap.
     */
    public static function lite_app_cap(): ?int {
        // Default null (no cap). The filter stays available for one
        // back-compat minor so any external code that hooked it doesn't
        // explode silently; it can now only LOWER, not establish, a cap.
        $cap = apply_filters('dsgo_apps_lite_app_cap', null);
        if ($cap === null || $cap === false) return null;
        $cap = (int) $cap;
        return $cap > 0 ? $cap : null;
    }

    /** Number of published `dsgo_app` posts. Counts toward the Lite cap. */
    public static function count_published_apps(): int {
        $count = wp_count_posts(PostType::SLUG);
        return isset($count->publish) ? (int) $count->publish : 0;
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
        return Settings::app_base_path($manifest->id);
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
        $max = Bundle::max_total_bytes();
        if ($total > $max) {
            throw new InstallerError('bundle_too_large', sprintf('bundle is %d bytes (max %d)', $total, $max));
        }
    }

    private static function stash_existing(string $bundle_dir): ?string {
        if (!is_dir($bundle_dir)) {
            return null;
        }
        $stash = rtrim($bundle_dir, '/') . '.previous-' . uniqid();
        // Atomic stash of the existing install dir so we can roll back if extract fails.
        // WP_Filesystem::move() requires an FTP/SSH context unavailable in REST.
        if (!@rename($bundle_dir, $stash)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
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

        $bridge_client_path = DSGO_APPS_PATH . 'assets/bridge-client.js';
        $bridge_client_ver  = file_exists($bridge_client_path) ? (string) filemtime($bridge_client_path) : DSGO_APPS_VERSION;
        $bridge_client_url  = add_query_arg('ver', $bridge_client_ver, plugins_url('assets/bridge-client.js', DSGO_APPS_FILE));
        $script = $doc->createElement('script');
        $script->setAttribute('src', $bridge_client_url);
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

        // Apps that read site content (products, posts, users, media) get
        // image URLs back from the bridge pointing at the WP uploads root.
        // Without auto-including those origins, every featured_media_url /
        // product image / user avatar gets CSP-blocked even though the
        // bridge response contains the URL.
        $content_origins = CSPBuilder::content_image_origins($manifest);
        $img_src_extra   = $content_origins !== [] ? ' ' . implode(' ', $content_origins) : '';

        // Apps that opt into `content.blockStyles` render arbitrary block
        // markup whose stylesheets, fonts, images, and embedded sub-frames
        // live on the host site origin (`/wp-content/plugins/...`,
        // `/wp-content/themes/...`, `/wp-includes/...`). Without widening
        // CSP to the host origin those resources get blocked even though
        // the opt-in explicitly trusts them.
        //
        // `img-src` additionally allows any `https:` origin: page authors
        // routinely embed images from third-party hosts (Unsplash, CDNs,
        // gravatar variants, social embeds), and the opt-in already trusts
        // whatever the host's `wp_kses_post()` sanitizer let through.
        // Images cannot execute, so the marginal risk over allowing all
        // host-origin assets is minimal.
        $block_origin    = '';
        $block_img_extra = '';
        $frame_src       = "'none'";
        if ($manifest->content_block_styles !== []) {
            $block_origin    = ' ' . home_url();
            $block_img_extra = ' https:';
            $frame_src       = $block_origin;
        }

        // connect-src always includes the plugin assets dir so the browser
        // can fetch source maps (`bridge-client.js.map`) when DevTools is
        // open. Without this, every developer sees a CSP error on every
        // page load even though source maps are dev-only. App scripts
        // themselves cannot read the bridge client's body — `<script src>`
        // already loads it, and the response body is opaque to the app's
        // sandboxed origin regardless of CSP.
        //
        // The block-styles opt-in does NOT widen connect-src further —
        // same-origin XHR from injected block JS is rare and high-risk,
        // and the bridge itself uses postMessage, not fetch.
        $connect_sources = [$assets_url];
        if ($external !== []) {
            $connect_sources = array_merge($connect_sources, $external);
        }
        $connect_src = implode(' ', $connect_sources);

        // Note: `frame-ancestors` is intentionally omitted. This CSP is
        // delivered via a `<meta http-equiv>` element (DOMDocument-injected
        // into the bundle's index.html), and browsers ignore frame-ancestors
        // when delivered via meta. The outer iframe-loader response handles
        // cross-origin framing protection at the HTTP-header level.
        return implode('; ', [
            "default-src 'none'",
            "script-src $assets_url $bundle_url 'unsafe-inline'",
            "style-src $bundle_url 'unsafe-inline'" . $block_origin,
            "img-src $bundle_url data: blob:" . $img_src_extra . $block_origin . $block_img_extra,
            "font-src $bundle_url" . $block_origin,
            "connect-src $connect_src",
            "frame-src $frame_src",
        ]);
    }

    /**
     * Delete vault entries whose alias is no longer declared in the manifest.
     * Called from the install path on every successful update. The set of
     * "declared" aliases is the manifest's `secrets[]` array; the set of
     * "stored" aliases comes from Secret_Vault::list_set_aliases() (which
     * doesn't decrypt anything, so it's cheap).
     *
     * No-op on first install (vault is empty). Idempotent on re-runs.
     */
    private static function reconcile_vault_against_manifest(Manifest $manifest): void {
        $declared = array_column($manifest->secrets, 'alias');
        $stored   = Secret_Vault::list_set_aliases($manifest->id);
        foreach (array_diff($stored, $declared) as $orphan) {
            Secret_Vault::delete($manifest->id, $orphan);
        }
    }
}
