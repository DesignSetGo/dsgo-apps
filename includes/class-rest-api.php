<?php
/**
 * Custom REST endpoints under /wp-json/dsgo/v1/.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class RestApi {

    public const NAMESPACE = 'dsgo/v1';

    /** Hourly per-app cap on ai.prompt requests (per-IP for anon, per-user for auth). */
    public const AI_RATE_LIMIT_PER_HOUR = 60;

    /**
     * Capabilities the /can endpoint will probe. Restricting to a known list
     * avoids leaking results for arbitrary third-party caps and prevents the
     * post-meta cap resolver from running on attacker-supplied strings.
     */
    private const CAN_ALLOWED_CAPS = [
        'edit_posts',
        'edit_pages',
        'edit_others_posts',
        'edit_published_posts',
        'publish_posts',
        'read',
        'read_private_posts',
        'manage_options',
        'upload_files',
        'unfiltered_html',
    ];

    public static function register(): void {
        register_rest_route(self::NAMESPACE, '/apps', [
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'create_app'],
                'permission_callback' => static fn () => current_user_can('manage_options'),
            ],
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'list_apps'],
                'permission_callback' => static fn () => current_user_can('manage_options'),
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/apps/import-html', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'import_html'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);
        register_rest_route(self::NAMESPACE, '/apps/install-starter', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'install_starter'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route(self::NAMESPACE, '/apps/(?P<id>[a-z][a-z0-9-]{2,63})', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'delete_app'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route(self::NAMESPACE, '/site-home', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'set_site_home'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route(self::NAMESPACE, '/can', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'can'],
            'permission_callback' => static fn () => is_user_logged_in(),
            'args'                => ['cap' => ['required' => true, 'type' => 'string']],
        ]);

        // site.info — emits the fields the bridge spec requires that the
        // built-in WP REST root index doesn't expose (admin_email,
        // language, date_format, time_format). The bridge transport calls
        // this and forwards the body straight through.
        register_rest_route(self::NAMESPACE, '/site-info', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'site_info'],
            'permission_callback' => '__return_true',
        ]);

        $app_id_re = '(?P<app_id>[a-z][a-z0-9-]{2,63})';
        $key_re    = '(?P<key>[a-zA-Z0-9._-]{1,128})';

        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/storage/app/$key_re", [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'storage_app_get'],
                'permission_callback' => [self::class, 'permit_storage'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'storage_app_set'],
                'permission_callback' => [self::class, 'permit_storage'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/storage/user/$key_re", [
            [
                // Anonymous GET allowed; handler returns null so apps can fall back to localStorage.
                'methods'             => 'GET',
                'callback'            => [self::class, 'storage_user_get'],
                'permission_callback' => [self::class, 'permit_storage'],
            ],
            [
                // SET requires login (handler returns 401 via Storage::user_set's not_authenticated).
                'methods'             => 'PUT',
                'callback'            => [self::class, 'storage_user_set'],
                'permission_callback' => [self::class, 'permit_storage'],
            ],
        ]);

        // Abilities — list and invoke. Permission is checked inside the callback
        // against the manifest (same posture as storage routes).
        $ability_name_re = '(?P<ability_name>[a-z0-9-]+/[a-z0-9-]+)';
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/abilities", [
            'methods'             => 'GET',
            'callback'            => [self::class, 'abilities_list'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/abilities/$ability_name_re", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'abilities_invoke'],
            'permission_callback' => '__return_true',
        ]);
        // ai.prompt and email.send are mutating operations that consume
        // billable resources (LLM tokens; outbound mail). Require an
        // authenticated WP session as a coarse gate; per-manifest permission
        // checks then run inside the callback against the app's permissions.
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/ai/prompt", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'ai_prompt'],
            'permission_callback' => static fn () => is_user_logged_in(),
        ]);
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/email/send", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'email_send'],
            'permission_callback' => static fn () => is_user_logged_in(),
        ]);
        // media.upload — core, opt-out: every app gets it unless the manifest
        // sets `media.uploads: false`. The visitor must hold the WP
        // `upload_files` capability (Author+ by default), so anonymous and
        // contributor-tier visitors can never reach the bridge handler.
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/media/upload", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'media_upload'],
            'permission_callback' => static fn () => is_user_logged_in() && current_user_can('upload_files'),
        ]);

        // Commerce — single dispatcher route. Action path is "<group>/<verb>"
        // e.g. "products/list", "cart/add-item", "checkout/open-hosted-page".
        // Permissions checked inside the callback against manifest.commerce.
        $commerce_action_re = '(?P<commerce_action>[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*)';
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/commerce/$commerce_action_re", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'commerce_invoke'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function list_apps(\WP_REST_Request $req): \WP_REST_Response {
        $page     = max(1, (int) ($req->get_param('page') ?? 1));
        $per_page = (int) ($req->get_param('per_page') ?? 100);
        $per_page = max(1, min(100, $per_page));
        // Only run the SQL_CALC_FOUND_ROWS count when the caller asks for it;
        // the admin UI only needs the totals when paginating beyond the first
        // page, so default to skipping the extra full-table scan.
        $want_total = (bool) $req->get_param('counts');

        $query = new \WP_Query([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => !$want_total,
        ]);

        $out = [];
        foreach ($query->posts as $p) {
            $manifest   = get_post_meta($p->ID, 'dsgo_apps_manifest', true);
            $isolation  = is_array($manifest) ? ($manifest['isolation'] ?? 'inline') : 'inline';
            $mount_mode = is_array($manifest) ? ($manifest['mount']['mode'] ?? 'prefixed') : 'prefixed';
            $base_path  = $mount_mode === 'root' ? '/' : Settings::app_base_path($p->post_name);
            $modes      = is_array($manifest) ? ($manifest['display']['modes'] ?? []) : [];
            $out[] = [
                'id'            => $p->post_name,
                'name'          => $p->post_title,
                'version'       => is_array($manifest) ? ($manifest['version'] ?? null) : null,
                'modes'         => $modes,
                'isolation'     => $isolation,
                'mount'         => ['mode' => $mount_mode],
                'url'           => home_url($base_path),
                'routes'        => is_array($manifest) && $isolation === 'inline' ? ($manifest['routes'] ?? []) : [],
                'is_site_home'  => $mount_mode === 'root',
                'home_eligible' => is_array($modes) && in_array('page', $modes, true),
            ];
        }

        $response = new \WP_REST_Response($out, 200);
        $response->header('X-WP-Total', (string) (int) $query->found_posts);
        $response->header('X-WP-TotalPages', (string) (int) $query->max_num_pages);
        return $response;
    }

    public static function create_app(\WP_REST_Request $req): \WP_REST_Response {
        $files = $req->get_file_params();
        if (empty($files['bundle']) || !is_array($files['bundle'])) {
            return new \WP_REST_Response(['code' => 'missing_file', 'message' => 'expected multipart "bundle" field'], 400);
        }
        $zip_path = $files['bundle']['tmp_name'];
        try {
            $result = Installer::install($zip_path, get_current_user_id());
            return new \WP_REST_Response([
                'id'      => $result->app_id,
                'url'     => $result->url,
                'post_id' => $result->post_id,
            ], 201);
        } catch (InstallerError $e) {
            $status = match ($e->error_code) {
                'forbidden'             => 403,
                'not_found'             => 404,
                'root_mount_conflict'   => 409,
                'invalid_zip',
                'missing_manifest',
                'invalid_manifest',
                'invalid_entry_html',
                'unsafe_path',
                'forbidden_extension',
                'too_many_files',
                'bundle_too_large'      => 422,
                default                 => 500,
            };
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        }
    }

    /**
     * POST /dsgo/v1/apps/import-html — wrap an uploaded static artifact in a
     * synthesized iframe-mode manifest and run it through the same install
     * pipeline as a zip upload. Accepts two file shapes:
     *
     *   1. A single .html file (downloaded Claude Artifact, single-file game,
     *      etc.) → wrapped as `index.html` inside a one-file bundle.
     *   2. A .zip without a `dsgo-app.json` (Claude Design export, single-page
     *      static site, etc.) → preserved with its directory layout, with a
     *      manifest synthesized at the root pointing at the detected entry.
     *
     * Multipart fields:
     *   - file: the HTML or zip file (required)
     *   - id, name, version: text fields (id required; name + version optional)
     */
    public static function import_html(\WP_REST_Request $req): \WP_REST_Response {
        $files  = $req->get_file_params();
        $params = $req->get_body_params();
        $id      = isset($params['id']) && is_string($params['id']) ? $params['id'] : '';
        $name    = isset($params['name']) && is_string($params['name']) && $params['name'] !== '' ? $params['name'] : null;
        $version = isset($params['version']) && is_string($params['version']) && $params['version'] !== '' ? $params['version'] : null;

        if ($id === '') {
            return new \WP_REST_Response(['code' => 'missing_field', 'message' => 'id is required'], 400);
        }
        if (empty($files['file']) || !is_array($files['file']) || empty($files['file']['tmp_name'])) {
            return new \WP_REST_Response(['code' => 'missing_file', 'message' => 'expected multipart "file" field'], 400);
        }

        $upload = $files['file'];
        if (isset($upload['error']) && $upload['error'] !== UPLOAD_ERR_OK) {
            return new \WP_REST_Response(['code' => 'upload_failed', 'message' => sprintf('upload error code %d', $upload['error'])], 400);
        }

        $is_zip = self::looks_like_zip($upload);

        $zip_path = null;
        try {
            if ($is_zip) {
                $zip_path = ArtifactNormalizer::pack_static_zip($upload['tmp_name'], $id, $name, $version);
            } else {
                $body = (string) file_get_contents($upload['tmp_name']);
                $zip_path = ArtifactNormalizer::pack_html($body, $id, $name, $version);
            }
            $result = Installer::install($zip_path, get_current_user_id());
            return new \WP_REST_Response([
                'id'      => $result->app_id,
                'url'     => $result->url,
                'post_id' => $result->post_id,
            ], 201);
        } catch (ArtifactNormalizerError $e) {
            $status = match ($e->error_code) {
                'invalid_id', 'invalid_version',
                'empty_html', 'invalid_html', 'artifact_too_large',
                'invalid_zip', 'manifest_present', 'unsafe_path',
                'empty_bundle', 'missing_entry_html', 'too_many_files' => 422,
                default                                                => 500,
            };
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        } catch (InstallerError $e) {
            $status = match ($e->error_code) {
                'forbidden'             => 403,
                'not_found'             => 404,
                'root_mount_conflict'   => 409,
                'invalid_zip',
                'missing_manifest',
                'invalid_manifest',
                'invalid_entry_html',
                'unsafe_path',
                'forbidden_extension',
                'too_many_files',
                'bundle_too_large'      => 422,
                default                 => 500,
            };
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        } finally {
            if (is_string($zip_path)) {
                $work_dir = dirname($zip_path);
                if (is_dir($work_dir) && str_contains($work_dir, 'dsgo-artifact-')) {
                    // Per-request temp scratch dir; WP_Filesystem can't operate
                    // on get_temp_dir() reliably without an FTP/SSH context.
                    @unlink($zip_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @rmdir($work_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
                }
            }
        }
    }

    /**
     * Decide whether an uploaded file should be treated as a zip bundle (vs a
     * single HTML body) for the import-html dispatcher. Trusts the magic-number
     * sniff over the filename so a renamed `.html` zip still lands on the right
     * branch.
     *
     * @param array{tmp_name?:string,name?:string,type?:string} $upload
     */
    private static function looks_like_zip(array $upload): bool {
        $tmp = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        if ($tmp !== '' && is_readable($tmp)) {
            // Reading the first 4 bytes of an upload to magic-sniff. WP_Filesystem
            // has no streaming primitive for this — get_contents() would slurp
            // the entire upload into memory for a 4-byte check.
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $fh = @fopen($tmp, 'rb');
            if ($fh !== false) {
                $head = (string) fread($fh, 4);
                fclose($fh);
                // PK\x03\x04 — local file header magic for zip archives.
                if (str_starts_with($head, "PK\x03\x04")) return true;
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
        $filename = isset($upload['name']) ? (string) $upload['name'] : '';
        if ($filename !== '' && preg_match('/\.zip$/i', $filename) === 1) return true;
        $type = isset($upload['type']) ? strtolower((string) $upload['type']) : '';
        return $type === 'application/zip' || $type === 'application/x-zip-compressed';
    }

    /**
     * POST /dsgo/v1/apps/install-starter — install the bundled starter app.
     *
     * Zips the plugin's `assets/starter/` directory on the fly and feeds it
     * through the same `Installer::install` pipeline as a bundle upload. Lets
     * a freshly-installed plugin go from zero to a working multi-page app
     * with one click — no terminal, no build step.
     */
    public static function install_starter(\WP_REST_Request $req): \WP_REST_Response {
        $starter_dir = DSGO_APPS_PATH . 'assets/starter';
        if (!is_dir($starter_dir) || !is_file($starter_dir . '/dsgo-app.json')) {
            return new \WP_REST_Response([
                'code'    => 'starter_missing',
                'message' => 'bundled starter not found in plugin assets/starter/',
            ], 500);
        }

        $work_dir = trailingslashit(get_temp_dir()) . 'dsgo-starter-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($work_dir)) {
            return new \WP_REST_Response(['code' => 'fs_error', 'message' => 'cannot create temp dir'], 500);
        }
        $zip_path = $work_dir . '/starter.zip';

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return new \WP_REST_Response(['code' => 'fs_error', 'message' => 'cannot create starter zip'], 500);
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($starter_dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            $base_len = strlen($starter_dir) + 1;
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $abs = $file->getPathname();
                $rel = str_replace('\\', '/', substr($abs, $base_len));
                if ($rel === '') continue;
                $zip->addFile($abs, $rel);
            }
            $zip->close();

            $result = Installer::install($zip_path, get_current_user_id());
            return new \WP_REST_Response([
                'id'      => $result->app_id,
                'url'     => $result->url,
                'post_id' => $result->post_id,
            ], 201);
        } catch (InstallerError $e) {
            $status = match ($e->error_code) {
                'forbidden'             => 403,
                'not_found'             => 404,
                'root_mount_conflict'   => 409,
                'install_in_progress'   => 409,
                'invalid_zip',
                'missing_manifest',
                'invalid_manifest',
                'invalid_entry_html',
                'invalid_route_html',
                'missing_route_file',
                'unsafe_path',
                'forbidden_extension',
                'too_many_files',
                'bundle_too_large'      => 422,
                default                 => 500,
            };
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        } finally {
            // Per-request scratch dir under get_temp_dir(); WP_Filesystem can't
            // be relied on without an FTP context, and these paths are always
            // bounded to dsgo-starter-* / dsgo-artifact-* directories we created.
            if (is_file($zip_path)) @unlink($zip_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if (is_dir($work_dir)) @rmdir($work_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }
    }

    public static function delete_app(\WP_REST_Request $req): \WP_REST_Response {
        $id = $req['id'];
        $post = get_page_by_path($id, OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app does not exist'], 404);
        }
        Bundle::recursive_delete(Bundle::dir_for($id));

        // Cascade-delete per-user storage (post meta cascades automatically; user meta does not).
        // On a site with millions of users + per-user app storage, a single
        // DELETE locks too many rows; schedule batched cleanup via WP-Cron
        // and run one batch inline so smaller sites still see immediate
        // cleanup. The cron handler keeps re-scheduling itself until the
        // table is drained.
        self::cleanup_user_storage_batch((int) $post->ID);

        AbilitiesPublisher::unregister_for_app($id);
        wp_delete_post($post->ID, true);
        Settings::refresh_root_app_id();
        SitemapProvider::invalidate_cache();
        // No flush_rewrite_rules — see Installer::install for rationale.
        return new \WP_REST_Response(['ok' => true], 200);
    }

    /**
     * POST /dsgo/v1/site-home — promote one app to the site root, demote
     * the current root app, or both. Body: `{"app_id":"<slug>"|null}`. A
     * null/empty `app_id` demotes the current root app (if any) without
     * promoting anything, returning the site root to WP's default.
     *
     * Source of truth for "is this app root-mounted" is the
     * `dsgo_apps_mount_mode` post meta; we keep the serialized manifest's
     * `mount.mode` in sync so list_apps and any code that re-reads the
     * manifest meta stays coherent.
     */
    public static function set_site_home(\WP_REST_Request $req): \WP_REST_Response {
        $body   = $req->get_json_params();
        $app_id = is_array($body) && array_key_exists('app_id', $body) ? $body['app_id'] : null;
        if ($app_id !== null && !is_string($app_id)) {
            return new \WP_REST_Response(['code' => 'invalid_app_id', 'message' => 'app_id must be a string or null'], 400);
        }
        if (is_string($app_id) && $app_id !== '' && preg_match('/^[a-z][a-z0-9-]{2,63}$/', $app_id) !== 1) {
            return new \WP_REST_Response(['code' => 'invalid_app_id', 'message' => 'app_id is not a valid slug'], 400);
        }
        $promote_id = is_string($app_id) && $app_id !== '' ? $app_id : null;

        if ($promote_id !== null) {
            $new_post = get_page_by_path($promote_id, OBJECT, PostType::SLUG);
            if (!$new_post || $new_post->post_status !== 'publish') {
                return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app does not exist'], 404);
            }
            $new_manifest = get_post_meta($new_post->ID, 'dsgo_apps_manifest', true);
            if (!is_array($new_manifest)) {
                return new \WP_REST_Response(['code' => 'invalid_manifest', 'message' => 'app manifest is missing'], 500);
            }
            if (!self::is_home_eligible_manifest($new_manifest)) {
                return new \WP_REST_Response(
                    ['code' => 'not_eligible', 'message' => 'app must support "page" display mode to be set as site home'],
                    422,
                );
            }
        }

        $current_id = Settings::get_root_app_id();
        if ($current_id !== null && $current_id !== $promote_id) {
            $cur_post = get_page_by_path($current_id, OBJECT, PostType::SLUG);
            if ($cur_post) {
                self::write_mount_mode((int) $cur_post->ID, 'prefixed');
                InlineRenderer::bump_cache_version($current_id);
            }
        }

        if ($promote_id !== null && $promote_id !== $current_id) {
            self::write_mount_mode((int) $new_post->ID, 'root');
            InlineRenderer::bump_cache_version($promote_id);
        }

        Settings::refresh_root_app_id();

        return new \WP_REST_Response([
            'ok'      => true,
            'home_id' => Settings::get_root_app_id(),
        ], 200);
    }

    /**
     * Update both the dedicated `dsgo_apps_mount_mode` meta key and the
     * mirrored `mount.mode` field in the serialized manifest so callers that
     * read either source see the same value.
     */
    private static function write_mount_mode(int $post_id, string $mode): void {
        update_post_meta($post_id, 'dsgo_apps_mount_mode', $mode);
        $manifest = get_post_meta($post_id, 'dsgo_apps_manifest', true);
        if (is_array($manifest)) {
            $manifest['mount'] = ['mode' => $mode];
            update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
        }
    }

    /**
     * An app is home-eligible iff it can render at the site root URL — which
     * means its `display.modes` includes `page`. Inline apps are constrained
     * to page-only by the manifest validator, so this only filters out
     * iframe-mode apps that opted out of page rendering (e.g. block-only
     * embeds).
     *
     * @param array<string, mixed> $manifest
     */
    private static function is_home_eligible_manifest(array $manifest): bool {
        $modes = $manifest['display']['modes'] ?? [];
        return is_array($modes) && in_array('page', $modes, true);
    }

    public const USER_STORAGE_CLEANUP_HOOK = 'dsgo_apps_cleanup_user_storage';
    private const USER_STORAGE_CLEANUP_BATCH = 1000;

    /**
     * Delete up to USER_STORAGE_CLEANUP_BATCH usermeta rows for the given app
     * post ID; if more remain, schedule another single-event cron tick to
     * keep going. Bounded per-call so the request returns fast and the DB
     * never sees a multi-million-row DELETE.
     */
    public static function cleanup_user_storage_batch(int $post_id): void {
        global $wpdb;
        $value_prefix = $wpdb->esc_like('dsgo_apps_storage_user_' . $post_id . '_') . '%';
        $size_key     = 'dsgo_apps_storage_size_user_' . $post_id;

        // No usermeta API can match by meta_key prefix across all users; this
        // runs from a cron-bounded batch so caching is intentionally skipped.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT umeta_id FROM {$wpdb->usermeta}
             WHERE meta_key LIKE %s OR meta_key = %s
             LIMIT %d",
            $value_prefix,
            $size_key,
            self::USER_STORAGE_CLEANUP_BATCH,
        ));
        if (!$ids) {
            return;
        }
        // Bulk DELETE by umeta_id uses an IN-clause with as many %d placeholders
        // as ids; prepare() expands them safely. Cron-bounded batch — no caching.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ($placeholders)",
            ...array_map('intval', $ids),
        ));
        if (count($ids) >= self::USER_STORAGE_CLEANUP_BATCH) {
            wp_schedule_single_event(time() + 30, self::USER_STORAGE_CLEANUP_HOOK, [$post_id]);
        }
    }

    /**
     * Storage routes carry a per-(user, app) nonce in `X-DSGo-App-Nonce`.
     * The bridge bootstrap mints `wp_create_nonce(self::app_nonce_action(...))`
     * at render time so legitimate calls pass the check; a malicious app A
     * trying to read or overwrite app B's storage via a direct fetch would
     * be holding its own nonce, and `wp_verify_nonce` against
     * `dsgo_app_<user>_B` fails because the action ID baked into A's nonce
     * is `dsgo_app_<user>_A`.
     *
     * Note on the broader threat model: inline-mode apps share a window with
     * the bridge plumbing and run with the rendering visitor's WP REST
     * capabilities. This nonce only isolates DSGo's own storage endpoints
     * from cross-app access — it doesn't (and can't) prevent an inline-mode
     * app from calling other same-origin REST endpoints (`wp/v2/users`,
     * etc.) with the visitor's caps. Authors of untrusted code should ship
     * iframe-mode bundles, where CSP `connect-src 'none'` blocks any
     * direct fetch.
     */
    public static function permit_storage(\WP_REST_Request $req): bool|\WP_Error {
        $app_id = (string) $req['app_id'];
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_Error('not_found', 'app does not exist', ['status' => 404]);
        }
        $nonce = $req->get_header('X-DSGo-App-Nonce');
        if (!is_string($nonce) || $nonce === '') {
            return new \WP_Error(
                'rest_forbidden',
                'X-DSGo-App-Nonce header missing — storage calls must come from the app whose URL they target',
                ['status' => 403],
            );
        }
        $user_id = get_current_user_id();
        if (!wp_verify_nonce($nonce, self::app_nonce_action($user_id, $app_id))) {
            return new \WP_Error(
                'rest_forbidden',
                'X-DSGo-App-Nonce does not match this app — cross-app storage access is rejected',
                ['status' => 403],
            );
        }
        return true;
    }

    /** Public helper: the action ID to mint a per-(user, app) storage nonce. */
    public static function app_nonce_action(int $user_id, string $app_id): string {
        return "dsgo_app_{$user_id}_{$app_id}";
    }

    public static function can(\WP_REST_Request $req): \WP_REST_Response {
        $cap = (string) $req['cap'];
        if (!in_array($cap, self::CAN_ALLOWED_CAPS, true)) {
            return new \WP_REST_Response(
                ['code' => 'invalid_params', 'message' => 'capability not in allowlist'],
                400,
            );
        }
        return new \WP_REST_Response(['can' => current_user_can($cap)], 200);
    }

    public static function site_info(\WP_REST_Request $req): \WP_REST_Response {
        $payload = [
            'title'       => (string) get_bloginfo('name'),
            'description' => (string) get_bloginfo('description'),
            'url'         => home_url(),
            // WP locales are `en_US`; the bridge spec promises BCP 47 (`en-US`).
            'language'    => str_replace('_', '-', is_user_logged_in() ? get_user_locale() : get_locale()),
            'timezone'    => (string) get_option('timezone_string', ''),
            'gmt_offset'  => (float) get_option('gmt_offset', 0),
            'date_format' => (string) get_option('date_format', ''),
            'time_format' => (string) get_option('time_format', ''),
        ];
        if (current_user_can('manage_options')) {
            $payload['admin_email'] = (string) get_option('admin_email', '');
        }
        return new \WP_REST_Response($payload, 200);
    }

    public static function storage_app_get(\WP_REST_Request $req): \WP_REST_Response {
        $post = get_page_by_path($req['app_id'], OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        return new \WP_REST_Response(['value' => Storage::app_get($post->ID, $req['key'])], 200);
    }

    public static function storage_app_set(\WP_REST_Request $req): \WP_REST_Response {
        $post = get_page_by_path($req['app_id'], OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        try {
            Storage::app_set($post->ID, $req['key'], $req->get_param('value'));
        } catch (StorageError $e) {
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], 422);
        }
        return new \WP_REST_Response(['ok' => true], 200);
    }

    public static function storage_user_get(\WP_REST_Request $req): \WP_REST_Response {
        $post = get_page_by_path($req['app_id'], OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        return new \WP_REST_Response(['value' => Storage::user_get($post->ID, get_current_user_id(), $req['key'])], 200);
    }

    public static function storage_user_set(\WP_REST_Request $req): \WP_REST_Response {
        $post = get_page_by_path($req['app_id'], OBJECT, PostType::SLUG);
        if (!$post) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        try {
            Storage::user_set($post->ID, get_current_user_id(), $req['key'], $req->get_param('value'));
        } catch (StorageError $e) {
            $status = $e->error_code === 'not_authenticated' ? 401 : 422;
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        }
        return new \WP_REST_Response(['ok' => true], 200);
    }

    public static function abilities_list(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!in_array(Permission::Abilities, $manifest->permissions_read, true)) {
            return new \WP_REST_Response(['code' => 'permission_denied', 'message' => 'app lacks "abilities" permission'], 403);
        }
        $list = AbilitiesBridge::list_for_app($manifest, get_current_user_id());
        return new \WP_REST_Response($list, 200);
    }

    public static function abilities_invoke(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!in_array(Permission::Abilities, $manifest->permissions_read, true)) {
            return new \WP_REST_Response(['code' => 'permission_denied', 'message' => 'app lacks "abilities" permission'], 403);
        }
        $name = (string) $req['ability_name'];
        $args = $req->get_param('args');
        if ($args !== null && !is_array($args)) {
            return new \WP_REST_Response(['code' => 'invalid_params', 'message' => '"args" must be an object when provided'], 400);
        }
        $result = AbilitiesBridge::invoke($name, $args ?? [], $manifest, get_current_user_id());
        if ($result['ok']) {
            return new \WP_REST_Response($result['data'] ?? null, 200);
        }
        $status = match ($result['code']) {
            'permission_denied' => 403,
            'not_found'         => 404,
            'invalid_params'    => 400,
            'not_implemented'   => 501,
            default             => 500,
        };
        $body = ['code' => $result['code'], 'message' => $result['message'] ?? ''];
        if (isset($result['reason']))        $body['reason'] = $result['reason'];
        if (isset($result['wp_error_code'])) $body['wp_error_code'] = $result['wp_error_code'];
        return new \WP_REST_Response($body, $status);
    }

    public static function ai_prompt(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!in_array(Permission::Ai, $manifest->permissions_read, true)) {
            return new \WP_REST_Response(['code' => 'permission_denied', 'message' => 'app lacks "ai" permission'], 403);
        }
        if (self::ai_rate_limited($manifest->id)) {
            $cap = (int) apply_filters('dsgo_apps_ai_rate_limit_per_hour', self::AI_RATE_LIMIT_PER_HOUR, $manifest->id);
            return new \WP_REST_Response(
                ['code' => 'rate_limited', 'message' => sprintf('app exceeded %d ai.prompt calls/hour', $cap)],
                429,
            );
        }
        $params = [
            'messages'   => $req->get_param('messages'),
            'tools'      => $req->get_param('tools'),
            'max_tokens' => $req->get_param('max_tokens'),
        ];
        $result = AiBridge::prompt($manifest, get_current_user_id(), $params);
        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }
        $status = match ($result['code']) {
            'permission_denied' => 403,
            'invalid_params'    => 400,
            'ai_not_configured' => 503,
            'not_implemented'   => 501,
            default             => 500,
        };
        $body = ['code' => $result['code'], 'message' => $result['message'] ?? ''];
        if (isset($result['reason']))        $body['reason'] = $result['reason'];
        if (isset($result['wp_error_code'])) $body['wp_error_code'] = $result['wp_error_code'];
        return new \WP_REST_Response($body, $status);
    }

    public static function media_upload(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!MediaBridge::is_enabled_for_app($manifest)) {
            return new \WP_REST_Response(
                ['code' => 'permission_denied', 'message' => 'media uploads are disabled for this app'],
                403,
            );
        }
        $files = $req->get_file_params();
        $file  = isset($files['file']) && is_array($files['file']) ? $files['file'] : [];
        $params = [
            'filename' => $req->get_param('filename'),
            'alt_text' => $req->get_param('alt_text'),
        ];
        $result = MediaBridge::upload($manifest, get_current_user_id(), $file, $params);
        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 201);
        }
        $status = match ($result['code']) {
            'permission_denied' => 403,
            'not_authenticated' => 401,
            'invalid_params'    => 400,
            'rate_limited'      => 429,
            'payload_too_large' => 413,
            default             => 500,
        };
        return new \WP_REST_Response(['code' => $result['code'], 'message' => $result['message'] ?? ''], $status);
    }

    public static function commerce_invoke(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!in_array(Permission::Commerce, $manifest->permissions_read, true)) {
            return new \WP_REST_Response(['code' => 'permission_denied', 'message' => 'app lacks "commerce" permission'], 403);
        }
        $raw_action = (string) $req['commerce_action'];
        // Wire format uses "products/list" etc.; bridge action is "products.list".
        $action = str_replace(['/', '-'], ['.', '_'], $raw_action);
        $params = $req->get_param('params');
        if ($params !== null && !is_array($params)) {
            return new \WP_REST_Response(['code' => 'invalid_params', 'message' => '"params" must be an object when provided'], 400);
        }
        $result = CommerceBridge::invoke($action, $params ?? [], $manifest, get_current_user_id());
        if ($result['ok']) {
            return new \WP_REST_Response($result['data'] ?? null, 200);
        }
        $status = match ($result['code']) {
            'permission_denied' => 403,
            'not_authenticated' => 401,
            'not_found'         => 404,
            'invalid_params'    => 400,
            'rate_limited'      => 429,
            'unknown_method'    => 404,
            'not_implemented'   => 501,
            default             => 500,
        };
        $body = ['code' => $result['code'], 'message' => $result['message'] ?? ''];
        if (isset($result['reason']))        $body['reason'] = $result['reason'];
        if (isset($result['wp_error_code'])) $body['wp_error_code'] = $result['wp_error_code'];
        return new \WP_REST_Response($body, $status);
    }

    public static function email_send(\WP_REST_Request $req): \WP_REST_Response {
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        if (!in_array(Permission::Email, $manifest->permissions_read, true)) {
            return new \WP_REST_Response(['code' => 'permission_denied', 'message' => 'app lacks "email" permission'], 403);
        }
        $params = [
            'to'      => $req->get_param('to'),
            'subject' => $req->get_param('subject'),
            'body'    => $req->get_param('body'),
            'isHtml'  => $req->get_param('isHtml'),
            'replyTo' => $req->get_param('replyTo'),
        ];
        $result = EmailBridge::send($manifest, get_current_user_id(), $params);
        if ($result['ok']) {
            return new \WP_REST_Response($result['data'], 200);
        }
        $status = match ($result['code']) {
            'permission_denied'  => 403,
            'not_authenticated'  => 401,
            'invalid_params'     => 400,
            'rate_limited'       => 429,
            default              => 500,
        };
        return new \WP_REST_Response(['code' => $result['code'], 'message' => $result['message'] ?? ''], $status);
    }

    /**
     * Load the stored manifest for the route's app_id. Manifests were validated
     * at install time and persisted to post meta as a trusted array, so we
     * skip re-validation on every bridge call.
     */
    private static function load_manifest_for_request(\WP_REST_Request $req): ?Manifest {
        $app_id = (string) ($req['app_id'] ?? '');
        if ($app_id === '') return null;
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') return null;
        $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw)) return null;
        try {
            return Manifest::from_array_unchecked($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Per-hour transient bucket for ai.prompt. Mirrors EmailBridge::is_rate_limited.
     */
    private static function ai_rate_limited(string $app_id): bool {
        $key   = sprintf('dsgo_ai_rate_%s_%s', $app_id, gmdate('YmdH'));
        $count = (int) get_transient($key);
        $cap   = (int) apply_filters('dsgo_apps_ai_rate_limit_per_hour', self::AI_RATE_LIMIT_PER_HOUR, $app_id);
        if ($count >= $cap) {
            return true;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS + 60);
        return false;
    }
}
