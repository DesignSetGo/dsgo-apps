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
     * Maximum bundle-history entries retained per app. When a new install
     * would push the count past this, the OLDEST entry's directory is
     * deleted and its meta row removed. 5 is the balance: enough to cover
     * 2-3 "I want to roll back the last Riff edit" cases plus the most
     * recent CLI deploy, without letting an aggressively iterating
     * session balloon disk usage. Filter via `dsgo_apps_max_bundle_history`.
     */
    public const MAX_HISTORY_ENTRIES = 5;

    /** Post meta key carrying the bundle-history list. */
    public const HISTORY_META_KEY = 'dsgo_apps_bundle_history';

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

        // Prepare the template context and capture the partial's output. The
        // markup lives in templates/install-consent.php so the installer stays
        // a data-only pipeline class (same pattern as the per-app admin tabs).
        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'manifest'            => $manifest,
            'previously_approved' => $previously_approved,
            'active_buckets'      => $active_buckets,
            'active_values'       => $active_values,
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        ob_start();
        require DSGO_APPS_PATH . 'templates/install-consent.php';
        return (string) ob_get_clean();
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

            update_post_meta($post_id, 'dsgo_apps_manifest', wp_slash($manifest->to_array()));
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

            // Bundle history: instead of deleting the rollback stash on
            // success, rename it into a permanent .history-{ts} directory
            // and record it in post meta. Lets the user revert to any of
            // the last MAX_HISTORY_ENTRIES versions if a Riff edit (or
            // any install path) silently dropped behavior. The free
            // plugin owns this because install/update lives here and
            // every code path (HTML upload, CLI deploy, Riff, webhooks)
            // benefits — safety, not premium.
            if ($rollback !== null && $prev_manifest !== null) {
                self::archive_to_history((int) $post_id, $rollback, $prev_manifest);
            } elseif ($rollback !== null) {
                // No prev_manifest means we couldn't parse the prior
                // install's metadata — archive without it would be
                // unrevertable (no version label, no schema check on
                // revert). Fall back to the old delete-stash behavior.
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
            if (class_exists(AbilitiesPublisher::class) && ProFeatureGate::is_enabled('abilities_publish')) {
                AbilitiesPublisher::register_for_app($manifest);
            }
            MediaPublisher::publish_for_app($manifest, $bundle_dir);

            // Cron + webhook surface activation (Task 15 of the cron+webhooks plan).
            //
            // CronScheduler::reconcile diffs prev vs new and only touches
            // jobs whose schedule/time/day_of_week changed — unchanged
            // jobs keep their next-fire timer, so re-installs of the same
            // manifest don't reset cron clocks.
            if (class_exists(CronScheduler::class) && ProFeatureGate::is_enabled('cron')) {
                CronScheduler::reconcile($manifest->id, $manifest, $prev_manifest);
            }
            // WebhookRouter::register is a no-op when the Pro gate is
            // closed (enforcement lives inside the router). When open,
            // newly added endpoints become callable on the next REST
            // request without waiting for rest_api_init's full sweep.
            if (class_exists(WebhookRouter::class) && ProFeatureGate::is_enabled('webhooks')) {
                WebhookRouter::register($manifest->id, $manifest->webhook_endpoints());
            }

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
    private const ALLOWED_EXTENSIONLESS_WELL_KNOWN = [
        '.well-known/api-catalog' => true,
    ];

    private static function install_url_path(Manifest $manifest): string {
        if ($manifest->mount_mode === MountMode::Root) {
            return '/';
        }
        return Settings::app_base_path($manifest->id);
    }

    private static function is_allowed_zip_entry_extension(string $name, string $ext): bool {
        if (Bundle::is_allowed_extension($ext)) {
            return true;
        }
        if ($ext !== '') {
            return false;
        }
        return isset(self::ALLOWED_EXTENSIONLESS_WELL_KNOWN[$name]);
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
            if (!self::is_allowed_zip_entry_extension($name, $ext)) {
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

    /**
     * Rename the rollback stash into a permanent .history-{ts} directory
     * and record it in post meta. Called once per successful install
     * where the prior bundle was parseable. Idempotent on the meta side
     * (skips duplicate ts entries). Prunes oldest when count > cap.
     *
     * Layout on disk:
     *   uploads/designsetgo-apps/{app_id}/                 ← current bundle
     *   uploads/designsetgo-apps/{app_id}.history-{ts1}/   ← prior version 1
     *   uploads/designsetgo-apps/{app_id}.history-{ts2}/   ← prior version 2
     *
     * Meta shape (list, newest-last):
     *   [
     *     { ts: 1715800000, version: "1.0.0", dir: "{app_id}.history-{ts}",
     *       manifest_snapshot: { ...full manifest array... } },
     *   ]
     */
    private static function archive_to_history(int $post_id, string $rollback_dir, Manifest $prev_manifest): void {
        if (!is_dir($rollback_dir)) {
            return; // stash was already moved/deleted; nothing to archive
        }
        $bundle_dir = Bundle::dir_for($prev_manifest->id);
        $parent     = dirname(rtrim($bundle_dir, '/'));
        $ts         = time();
        $basename   = basename(rtrim($bundle_dir, '/')) . '.history-' . $ts;
        $history_dir = $parent . '/' . $basename;

        // Collision guard: two installs in the same second would otherwise
        // try to rename to the same path. uniqid suffix only kicks in on
        // collision so the common case stays predictable (ts-only).
        if (is_dir($history_dir)) {
            $basename    .= '-' . substr(uniqid(), -6);
            $history_dir  = $parent . '/' . $basename;
        }

        if (!@rename($rollback_dir, $history_dir)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            // Rename failed (cross-filesystem, perms, race) — fall back
            // to the original delete-stash behavior so install still
            // succeeds. History feature is best-effort, not load-bearing.
            Bundle::recursive_delete($rollback_dir);
            return;
        }

        $entry = [
            'ts'                => $ts,
            'version'           => $prev_manifest->version,
            'dir'               => $basename,
            'manifest_snapshot' => $prev_manifest->to_array(),
        ];

        $existing = get_post_meta($post_id, self::HISTORY_META_KEY, true);
        $list     = is_array($existing) ? array_values($existing) : [];
        $list[]   = $entry;

        $list = self::prune_history($list, $parent);
        update_post_meta($post_id, self::HISTORY_META_KEY, $list);
    }

    /**
     * Trim the history list to MAX_HISTORY_ENTRIES (filterable), deleting
     * the dropped entries' on-disk directories. Returns the kept list.
     *
     * @param list<array{ts:int,version:string,dir:string,manifest_snapshot:array<string,mixed>}> $list
     */
    private static function prune_history(array $list, string $parent): array {
        $cap = (int) \apply_filters('dsgo_apps_max_bundle_history', self::MAX_HISTORY_ENTRIES);
        $cap = max(1, $cap);
        while (count($list) > $cap) {
            $dropped = array_shift($list);
            if (isset($dropped['dir']) && is_string($dropped['dir']) && $dropped['dir'] !== '') {
                $path = $parent . '/' . $dropped['dir'];
                // Defense-in-depth: only delete if the resolved path is
                // actually inside the parent dir AND has the .history-
                // marker. Prevents a poisoned meta row from triggering a
                // delete somewhere unexpected.
                if (str_contains($dropped['dir'], '.history-') && is_dir($path)) {
                    Bundle::recursive_delete($path);
                }
            }
        }
        return array_values($list);
    }

    /**
     * Return the history list for an app. Used by the per-app admin page
     * and by revert_to to look up the target.
     *
     * @return list<array{ts:int,version:string,dir:string,manifest_snapshot:array<string,mixed>}>
     */
    public static function list_history(int $post_id): array {
        $raw = get_post_meta($post_id, self::HISTORY_META_KEY, true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) continue;
            if (!isset($entry['ts'], $entry['version'], $entry['dir'])) continue;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Revert an installed app to a prior history entry. The current
     * bundle becomes a new history entry (so revert is itself
     * reversible). The chosen entry's directory is renamed back into
     * place as the active bundle, and the post's manifest meta is
     * updated to the historical snapshot.
     *
     * Caller is responsible for capability checks (manage_options).
     *
     * @throws InstallerError On lock contention, missing entry, fs error,
     *                        or unparseable manifest snapshot.
     */
    public static function revert_to(int $post_id, int $ts): void {
        $post = \get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_type !== PostType::SLUG) {
            throw new InstallerError('not_found', sprintf('post %d is not a dsgo_app', $post_id));
        }
        $app_id = (string) $post->post_name;

        $history = self::list_history($post_id);
        $target  = null;
        foreach ($history as $entry) {
            if ((int) $entry['ts'] === $ts) { $target = $entry; break; }
        }
        if ($target === null) {
            throw new InstallerError('not_found', sprintf('no history entry with ts=%d for %s', $ts, $app_id));
        }

        try {
            $target_manifest = Manifest::from_array_unchecked((array) $target['manifest_snapshot']);
        } catch (\Throwable) {
            throw new InstallerError('invalid_snapshot', 'stored manifest snapshot is unparseable');
        }

        if (!self::acquire_install_lock($app_id)) {
            throw new InstallerError('install_in_progress', sprintf('another install for "%s" is in progress', $app_id));
        }

        try {
            $bundle_dir  = Bundle::dir_for($app_id);
            $parent      = dirname(rtrim($bundle_dir, '/'));
            $target_path = $parent . '/' . (string) $target['dir'];

            if (!is_dir($target_path)) {
                throw new InstallerError('not_found', sprintf('history directory %s is missing on disk', $target['dir']));
            }

            // Stash the current bundle (will become a new history entry).
            $rollback = self::stash_existing($bundle_dir);

            // Move the target history dir into the active slot. Atomic.
            if (!@rename($target_path, $bundle_dir)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                if ($rollback !== null) {
                    @rename($rollback, $bundle_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                }
                throw new InstallerError('fs_error', 'cannot move history dir into active position');
            }

            // Update post meta to match the reverted manifest.
            update_post_meta($post_id, 'dsgo_apps_manifest', wp_slash($target_manifest->to_array()));
            update_post_meta($post_id, 'dsgo_apps_bundle_path', $bundle_dir);
            update_post_meta($post_id, 'dsgo_apps_installed_version', $target_manifest->version);
            update_post_meta($post_id, 'dsgo_apps_mount_mode', $target_manifest->mount_mode->value);
            update_post_meta($post_id, 'dsgo_apps_isolation', $target_manifest->isolation);

            // Drop the target from history (it's now active), and add the
            // pre-revert state as a new history entry so the user can
            // re-revert if they change their mind.
            $remaining = array_values(array_filter(
                $history,
                static fn(array $e) => (int) $e['ts'] !== $ts,
            ));
            if ($rollback !== null) {
                // Get the current manifest (the one we just stashed) for
                // the new history entry.
                $current_raw = get_post_meta($post_id, 'dsgo_apps_manifest', true);
                // ^ that's now the TARGET manifest (we just updated it).
                // We need the PRE-revert manifest. Read from the stashed
                // dir's dsgo-app.json.
                $stash_manifest_json = @file_get_contents($rollback . '/dsgo-app.json');
                $stash_manifest      = null;
                if (is_string($stash_manifest_json)) {
                    try {
                        $decoded = json_decode($stash_manifest_json, true);
                        if (is_array($decoded)) {
                            $stash_manifest = Manifest::from_array_unchecked($decoded);
                        }
                    } catch (\Throwable) { /* keep null */ }
                }
                if ($stash_manifest !== null) {
                    $new_ts        = time();
                    $new_basename  = basename(rtrim($bundle_dir, '/')) . '.history-' . $new_ts;
                    $new_dir       = $parent . '/' . $new_basename;
                    if (is_dir($new_dir)) { $new_basename .= '-' . substr(uniqid(), -6); $new_dir = $parent . '/' . $new_basename; }
                    if (@rename($rollback, $new_dir)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                        $remaining[] = [
                            'ts'                => $new_ts,
                            'version'           => $stash_manifest->version,
                            'dir'               => $new_basename,
                            'manifest_snapshot' => $stash_manifest->to_array(),
                        ];
                    } else {
                        Bundle::recursive_delete($rollback);
                    }
                } else {
                    // Couldn't read the pre-revert manifest — delete the
                    // stash rather than archive an unrevertable entry.
                    Bundle::recursive_delete($rollback);
                }
            }

            $remaining = self::prune_history($remaining, $parent);
            update_post_meta($post_id, self::HISTORY_META_KEY, $remaining);

            // Refresh caches that depend on installed bundle state.
            Settings::refresh_root_app_id();
            InlineRenderer::bump_cache_version($app_id);
            SitemapProvider::invalidate_cache();
        } finally {
            self::release_install_lock($app_id);
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

        $compat = $doc->createElement('script', self::artifact_compatibility_script());
        $compat->setAttribute('data-dsgo-artifact-compat', '');

        $bridge_client_path = DSGO_APPS_PATH . 'assets/bridge-client.js';
        $bridge_client_ver  = file_exists($bridge_client_path) ? (string) filemtime($bridge_client_path) : DSGO_APPS_VERSION;
        $bridge_client_url  = add_query_arg('ver', $bridge_client_ver, plugins_url('assets/bridge-client.js', DSGO_APPS_FILE));
        $script = $doc->createElement('script');
        $script->setAttribute('src', $bridge_client_url);
        $script->setAttribute('defer', '');

        $first = $head->firstChild;
        if ($first !== null) {
            $head->insertBefore($meta, $first);
            $head->insertBefore($compat, $meta);
            $head->insertBefore($script, $meta);
        } else {
            $head->appendChild($compat);
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
        // `'wasm-unsafe-eval'` permits WebAssembly.compile / instantiate
        // without enabling generic JS `eval()` — narrower than
        // `'unsafe-eval'`, which is deliberately NOT added. `worker-src
        // 'self'` lets bundles spawn Web Workers from their own assets;
        // without it browsers fall back to `script-src`, which is
        // inconsistent across implementations.
        return implode('; ', [
            "default-src 'none'",
            "script-src $assets_url $bundle_url 'unsafe-inline' 'wasm-unsafe-eval'",
            "style-src $bundle_url https://fonts.googleapis.com 'unsafe-inline'" . $block_origin,
            "img-src $bundle_url data: blob:" . $img_src_extra . $block_origin . $block_img_extra,
            "font-src $bundle_url https://fonts.gstatic.com data:" . $block_origin,
            "connect-src $connect_src",
            "worker-src $bundle_url",
            "frame-src $frame_src",
        ]);
    }

    private static function artifact_compatibility_script(): string {
        return <<<'JS'
(function () {
  function makeStorage() {
    var data = Object.create(null);
    return {
      get length() { return Object.keys(data).length; },
      key: function (index) { return Object.keys(data)[index] || null; },
      getItem: function (key) {
        key = String(key);
        return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null;
      },
      setItem: function (key, value) { data[String(key)] = String(value); },
      removeItem: function (key) { delete data[String(key)]; },
      clear: function () { data = Object.create(null); }
    };
  }
  function storageWorks(name) {
    try {
      var storage = window[name];
      var key = '__dsgo_probe__';
      storage.setItem(key, '1');
      storage.removeItem(key);
      return true;
    } catch (e) {
      return false;
    }
  }
  function installStorageFallback(name) {
    if (storageWorks(name)) return;
    try {
      Object.defineProperty(window, name, {
        configurable: true,
        value: makeStorage()
      });
    } catch (e) {}
  }
  function installCookieFallback() {
    try {
      void document.cookie;
      document.cookie = '__dsgo_probe__=1';
      return;
    } catch (e) {}
    var jar = Object.create(null);
    try {
      Object.defineProperty(document, 'cookie', {
        configurable: true,
        get: function () {
          return Object.keys(jar).map(function (key) {
            return key + '=' + jar[key];
          }).join('; ');
        },
        set: function (value) {
          var pair = String(value).split(';', 1)[0];
          var eq = pair.indexOf('=');
          if (eq < 1) return;
          var key = pair.slice(0, eq).trim();
          if (!key) return;
          jar[key] = pair.slice(eq + 1);
        }
      });
    } catch (e) {}
  }
  installStorageFallback('localStorage');
  installStorageFallback('sessionStorage');
  installCookieFallback();
})();
JS;
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
