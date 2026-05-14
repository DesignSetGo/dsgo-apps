<?php
/**
 * Custom REST endpoints under /wp-json/dsgo/v1/.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class RestApi {

    public const NAMESPACE = 'dsgo/v1';

    /** Hourly per-app cap on ai.prompt requests (per-IP for anon, per-user for auth). */
    public const AI_RATE_LIMIT_PER_HOUR = 60;

    /**
     * True when the current REST request authenticated via Application Password
     * (the auth method @designsetgo/cli uses). Set by the hook registered in
     * register() before any route handler runs.
     */
    private static bool $authed_via_app_password = false;

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
        // Capture whether the current REST request authenticated via Application
        // Password (the auth method @designsetgo/cli uses). The hook fires once
        // per request before the route handler runs, so the flag is set by the
        // time the install callback executes. The gate inside the install
        // handler is the only enforcement point for the cli_deploy feature.
        \add_action('application_password_did_authenticate', [self::class, 'mark_app_password_auth']);

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
        register_rest_route(self::NAMESPACE, '/apps/preview', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'preview_app'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
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

        // dsgo.help.method — runtime lookup of any bridge method's docs.
        // Always available (no manifest permission required) — the model's
        // discovery escape hatch for methods not enumerated in the harness
        // prompt. Read-only access to a small static JSON file; no rate limit
        // beyond the standard REST infrastructure.
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/help/methods/(?P<method>[a-z][a-z0-9_.-]*)", [
            'methods'             => 'GET',
            'callback'            => [self::class, 'help_method'],
            'permission_callback' => '__return_true',
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
        // ai.prompt requires an authenticated WP session as a coarse gate since
        // it consumes billable LLM tokens. email.send does not share this
        // requirement: sending to 'admin' is a legitimate anonymous operation
        // (contact forms). The per-manifest email.recipients allow-list and the
        // per-app rate limit (100/hour) are the correct abuse gates; login is
        // not. Anonymous visitors sending to 'current_user' already reject with
        // not_authenticated inside EmailBridge::send (get_current_user_id() === 0).
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/ai/prompt", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'ai_prompt'],
            'permission_callback' => static fn () => is_user_logged_in(),
        ]);
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/email/send", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'email_send'],
            'permission_callback' => '__return_true',
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

        // http.fetch — outbound HTTP proxy. The bridge enforces every gate
        // (manifest.permissions.http allowlist, SSRF, rate limit, etc.) so
        // the route's permission_callback is __return_true; the bridge then
        // rejects with the appropriate http_* error_code which the callback
        // maps to a REST status. Mirrors the abilities/commerce posture.
        register_rest_route(self::NAMESPACE, "/apps/$app_id_re/http/fetch", [
            'methods'             => 'POST',
            'callback'            => [self::class, 'http_fetch'],
            'permission_callback' => '__return_true',
            'args'                => [
                'url'        => ['required' => true,  'type' => 'string'],
                'method'     => ['required' => false, 'type' => 'string', 'default' => 'GET'],
                'headers'    => ['required' => false, 'type' => 'object'],
                'body'       => ['required' => false],
                'timeout_ms' => ['required' => false, 'type' => 'integer', 'default' => 10000],
            ],
        ]);

        // CLI preflight stub — registers at priority 20 so Pro's Cli_Auth
        // (which hooks at priority 10) wins when Pro is installed. The
        // callback bails immediately if the route is already registered.
        \add_action('rest_api_init', [self::class, 'register_cli_preflight_stub'], 20);
    }

    /**
     * Default CLI preflight responder. Returns the canonical "free" shape
     * the CLI uses to decide whether to attempt a deploy. Pro's Cli_Auth
     * overrides this route at a lower hook priority when installed.
     */
    public static function handle_cli_preflight_default(): \WP_REST_Response {
        return new \WP_REST_Response([
            'is_active'    => false,
            'plan'         => 'free',
            'capabilities' => ['multi_site_cli' => false],
        ], 200);
    }

    /**
     * Register the Lite CLI preflight stub if Pro has not already claimed
     * the route. Hooked on rest_api_init@20 so Pro's registration at
     * priority 10 runs first; when Pro is absent, the route doesn't exist
     * yet and the stub registers itself.
     */
    public static function register_cli_preflight_stub(): void {
        $routes = \rest_get_server()->get_routes();
        if (isset($routes['/dsgo/v1/cli/preflight'])) {
            // Pro already registered the real preflight; don't overwrite it.
            return;
        }
        \register_rest_route('dsgo/v1', '/cli/preflight', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_cli_preflight_default'],
            'permission_callback' => static fn () => \current_user_can('manage_options'),
        ]);
    }

    /**
     * Register admin-ajax handlers for the per-app Secrets tab. Each one is
     * gated on `manage_options` + a per-app nonce; the bridge layer never
     * sees these calls (they only flow between the admin UI and the vault).
     *
     * Wired separately from REST so the admin-ajax callbacks resolve even
     * on requests where rest_api_init doesn't fire. Plugin::register_hooks
     * binds this on `init` (not `admin_init`) because admin-ajax requests
     * fire init but not necessarily admin_init.
     */
    public static function register_admin_ajax(): void {
        add_action('wp_ajax_dsgo_apps_secret_set',         [self::class, 'ajax_secret_set']);
        add_action('wp_ajax_dsgo_apps_secret_clear',       [self::class, 'ajax_secret_clear']);
        add_action('wp_ajax_dsgo_apps_http_test',          [self::class, 'ajax_http_test']);
        add_action('wp_ajax_dsgo_apps_cron_run_now',       [self::class, 'ajax_cron_run_now']);
        add_action('wp_ajax_dsgo_apps_webhook_send_test',  [self::class, 'ajax_webhook_send_test']);
    }

    public static function mark_app_password_auth(): void {
        self::$authed_via_app_password = true;
    }

    public static function is_app_password_request(): bool {
        return self::$authed_via_app_password;
    }

    /** Reset the per-request AppPassword flag. Used by tests to prevent flag bleed between cases. */
    public static function reset_app_password_flag(): void {
        self::$authed_via_app_password = false;
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
            // has_secrets drives the per-row "Secrets" link in the apps list;
            // no Manifest::validate round-trip in the hot path. The Secrets
            // tab itself reads the manifest directly to render the form.
            $secrets_count = is_array($manifest) && is_array($manifest['secrets'] ?? null)
                ? count($manifest['secrets']) : 0;
            $out[] = [
                'id'            => $p->post_name,
                'name'          => $p->post_title,
                'version'       => is_array($manifest) ? ($manifest['version'] ?? null) : null,
                'modes'         => $modes,
                'isolation'     => $isolation,
                'mount'         => ['mode' => $mount_mode],
                'url'           => home_url($base_path),
                'routes'        => is_array($manifest) && $isolation === 'inline' ? ($manifest['routes'] ?? []) : [],
                'is_site_home'          => $mount_mode === 'root',
                'home_eligible'         => is_array($modes) && in_array('page', $modes, true),
                'has_secrets'           => $secrets_count > 0,
                'inactive_pro_features' => is_array($manifest)
                    ? AdminPage::inactive_pro_features_for_manifest($manifest)
                    : [],
            ];
        }

        $response = new \WP_REST_Response($out, 200);
        $response->header('X-WP-Total', (string) (int) $query->found_posts);
        $response->header('X-WP-TotalPages', (string) (int) $query->max_num_pages);
        return $response;
    }

    /**
     * POST /dsgo/v1/apps/preview — validate an uploaded bundle and compute
     * the bucket-consent payload WITHOUT performing the install. The admin
     * dropzone JS calls this first, shows the consent panel using
     * `rendered_html`, and on confirm re-uploads the same bundle to
     * `POST /dsgo/v1/apps` for the actual install.
     *
     * Multipart fields:
     *   - bundle: the .zip file (required)
     */
    public static function preview_app(\WP_REST_Request $req): \WP_REST_Response {
        $files = $req->get_file_params();
        if (empty($files['bundle']) || !is_array($files['bundle'])) {
            return new \WP_REST_Response(['code' => 'missing_file', 'message' => 'expected multipart "bundle" field'], 400);
        }
        $zip_path = $files['bundle']['tmp_name'];
        try {
            $result = Installer::preview($zip_path, get_current_user_id());
            return new \WP_REST_Response([
                'app_id'              => $result->app_id,
                'name'                => $result->name,
                'version'             => $result->version,
                'is_update'           => $result->is_update,
                'buckets'             => $result->buckets,
                'previously_approved' => $result->previously_approved,
                'new_buckets'         => $result->new_buckets,
                'removed_buckets'     => $result->removed_buckets,
                'rendered_html'       => $result->rendered_html,
            ], 200);
        } catch (InstallerError $e) {
            $status = self::installer_error_status($e->error_code);
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        }
    }

    public static function create_app(\WP_REST_Request $req): \WP_REST_Response {
        // CLI deploys gate on cli_deploy. Cookie-authed installs (the wp-admin
        // upload importer) skip the gate; AppPassword-authed installs (the CLI)
        // must be on Pro. This gate is the only enforcement point for cli_deploy.
        if (self::is_app_password_request() && !ProFeatureGate::is_enabled('cli_deploy')) {
            return new \WP_REST_Response([
                'code'    => 'cli_requires_pro',
                'message' => __('CLI deploys require a DesignSetGo Apps Pro license. The wp-admin upload importer is free and unlimited.', 'designsetgo-apps'),
                'data'    => [
                    'status'      => 402,
                    'pricing_url' => apply_filters('dsgo_apps_pro_pricing_url', 'https://designsetgo.dev/pricing'),
                ],
            ], 402);
        }

        $files = $req->get_file_params();
        if (empty($files['bundle']) || !is_array($files['bundle'])) {
            return new \WP_REST_Response(['code' => 'missing_file', 'message' => 'expected multipart "bundle" field'], 400);
        }
        $zip_path = $files['bundle']['tmp_name'];
        try {
            $result = Installer::install($zip_path, get_current_user_id());
            return new \WP_REST_Response(self::shape_install_response($result), 201);
        } catch (InstallerError $e) {
            $status = self::installer_error_status($e->error_code);
            return new \WP_REST_Response(['code' => $e->error_code, 'message' => $e->bare_message], $status);
        }
    }

    /**
     * Build the JSON shape returned from any install path. Centralized so
     * the html-import and starter-install paths return the same fields as
     * the bundle upload — particularly the `needs_secrets` + `secrets_url`
     * signals the admin JS uses to redirect to the Secrets tab after
     * installing an app that declares required_secrets.
     *
     * @return array<string, mixed>
     */
    private static function shape_install_response(InstallResult $result): array {
        $needs_secrets = false;
        $secrets_url   = null;

        // Read the just-installed manifest to compute the redirect signal.
        // Reading post meta is cheaper than re-validating the manifest, and
        // the values were trusted at install time.
        $post = get_post($result->post_id);
        if ($post instanceof \WP_Post) {
            $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
            $required = is_array($raw) && is_array($raw['required_secrets'] ?? null)
                ? array_values(array_filter($raw['required_secrets'], 'is_string'))
                : [];
            if ($required !== []) {
                // Compute "set" only when sodium is available — without it
                // the vault can't decrypt, so the missing-set is effectively
                // the full required set. We still surface needs_secrets=true
                // so the admin lands on the Secrets tab, where the
                // sodium-unavailable notice makes the degraded state visible.
                // Swallowing the redirect would leave a non-functional app
                // looking like a successful install.
                $set = Secret_Vault::is_available()
                    ? Secret_Vault::list_set_aliases($result->app_id)
                    : [];
                if (array_diff($required, $set) !== []) {
                    $needs_secrets = true;
                    $secrets_url = add_query_arg(
                        [
                            'page'           => 'designsetgo-apps',
                            'app_id'         => $result->app_id,
                            'tab'            => 'secrets',
                            'just_installed' => '1',
                        ],
                        admin_url('admin.php'),
                    );
                }
            }
        }

        return [
            'id'            => $result->app_id,
            'url'           => $result->url,
            'post_id'       => $result->post_id,
            'needs_secrets' => $needs_secrets,
            'secrets_url'   => $secrets_url,
        ];
    }

    /**
     * Map an InstallerError code to its HTTP status. Shared between
     * create_app(), preview_app(), and any future endpoint that surfaces
     * installer validation outcomes.
     */
    private static function installer_error_status(string $error_code): int {
        return match ($error_code) {
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
            return new \WP_REST_Response(self::shape_install_response($result), 201);
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
            return new \WP_REST_Response(self::shape_install_response($result), 201);
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
        // Unschedule any cron events bound to this app's jobs (Task 15
        // of the cron+webhooks plan). Without this, deleting an app
        // leaves orphan cron events that fire and log cron_app_not_found
        // indefinitely. Read the prior manifest to enumerate job ids;
        // if the manifest is malformed we skip silently — the
        // dispatcher's own app-not-found guard catches leftover events
        // on next fire.
        $stored_manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (is_array($stored_manifest)) {
            $job_ids = [];
            foreach ($stored_manifest['scheduled']['jobs'] ?? [] as $job) {
                if (is_array($job) && isset($job['id']) && is_string($job['id'])) {
                    $job_ids[] = $job['id'];
                }
            }
            if ($job_ids !== []) {
                CronScheduler::unschedule_all($id, $job_ids);
            }
        }
        // Purge the per-app sodium-encrypted secret vault. The plugin-wide
        // uninstall.php sweep catches stragglers via wp_options LIKE, but
        // a per-app delete should drop the encrypted blob immediately
        // rather than leaving it sitting in wp_options. delete_all is a
        // delete_option call — no decryption, no sodium dependency.
        Secret_Vault::delete_all($id);
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
            update_post_meta($post_id, 'dsgo_apps_manifest', wp_slash($manifest));
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
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ($placeholders)",
            ...array_map('intval', $ids),
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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

    /**
     * GET /dsgo/v1/apps/<app_id>/help/methods/<method>
     *
     * Read-only lookup of any bridge method's full documentation. Always
     * available — no manifest permission gate. Returns 404 for unknown
     * method names so the model can detect typos cleanly.
     */
    public static function help_method(\WP_REST_Request $req): \WP_REST_Response {
        // Confirm the app exists. Spelling errors in the URL path or routes
        // pointing at a deleted app should return not_found, not method docs.
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        $method = (string) $req['method'];
        $result = Help_Bridge::method($method);
        if (is_wp_error($result)) {
            $status = $result->get_error_data('status') ?? 404;
            return new \WP_REST_Response(['code' => $result->get_error_code(), 'message' => $result->get_error_message()], (int) $status);
        }
        return new \WP_REST_Response($result, 200);
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
     * POST /dsgo/v1/apps/{app_id}/http/fetch — proxy a single outbound HTTP
     * request through Http_Proxy_Bridge. The bridge runs the 13-step
     * enforcement pipeline and returns either a success result or an
     * http_* error_code; this callback maps those onto REST status codes
     * per the spec (403/422/413/429/502/200).
     *
     * Resolves the app's manifest from post meta and forwards directly —
     * no Manifest::validate() round-trip per request, matching the
     * ai_prompt / email_send pattern.
     */
    public static function http_fetch(\WP_REST_Request $req): \WP_REST_Response {
        if (!Secret_Vault::is_available()) {
            // Without libsodium we cannot decrypt vaulted secrets, so any
            // app that uses `{{ALIAS}}` substitution would fail mid-flight.
            // Surface the missing-extension condition up front instead of
            // letting it bubble out of the bridge as a generic 500.
            return new \WP_REST_Response([
                'code'    => 'sodium_unavailable',
                'message' => 'HTTP proxy requires the sodium PHP extension — contact your host',
            ], 503);
        }
        $manifest = self::load_manifest_for_request($req);
        if ($manifest === null) {
            return new \WP_REST_Response(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        $init = [
            'method'     => (string) ($req->get_param('method') ?? 'GET'),
            'headers'    => is_array($req->get_param('headers')) ? $req->get_param('headers') : [],
            'body'       => $req->get_param('body'),
            'timeout_ms' => (int) ($req->get_param('timeout_ms') ?? 10000),
        ];
        $url    = (string) $req->get_param('url');
        $result = Http_Proxy_Bridge::fetch($manifest, $url, $init);

        // Success: object{ok:true, status, headers, body}.
        if (isset($result->ok) && $result->ok === true) {
            return new \WP_REST_Response([
                'ok'      => true,
                'status'  => $result->status,
                'headers' => $result->headers,
                'body'    => $result->body,
            ], 200);
        }

        // Failure: object{error_code, message[, retry_after_seconds]}.
        $code = (string) ($result->error_code ?? 'http_network_error');
        $status = match ($code) {
            'http_permission_denied'    => 403,
            'http_invalid_url',
            'http_method_not_allowed',
            'http_host_not_allowed',
            'http_invalid_header',
            'http_invalid_body',
            'http_unknown_secret',
            'http_secret_not_set'        => 422,
            'http_request_too_large'    => 413,
            'http_response_too_large'   => 502,
            'http_ssrf_blocked'         => 502,
            'http_rate_limited'         => 429,
            'http_timeout'              => 504,
            'http_transport_unsupported'=> 503,
            default                     => 502,   // http_network_error + any new error_code
        };
        $body = ['code' => $code, 'message' => (string) ($result->message ?? '')];
        if (isset($result->retry_after_seconds)) {
            $body['retry_after_seconds'] = (int) $result->retry_after_seconds;
        }
        return new \WP_REST_Response($body, $status);
    }

    // ---- admin-ajax: Secrets tab ----

    /**
     * Per-app nonce action name. The Secrets tab template (Phase 7) emits
     * `wp_create_nonce(dsgo_apps_secret_nonce_action($app_id))` into the
     * page, and each ajax handler calls check_ajax_referer with the
     * matching action. Per-app nonces (not a single site-wide nonce)
     * limit blast radius if a referer ever leaked.
     */
    private static function secret_nonce_action(string $app_id): string {
        return 'dsgo_apps_secret_nonce_' . $app_id;
    }

    /**
     * Common gate for all three Secrets-tab handlers:
     *  - manage_options
     *  - nonce check
     *  - alias matches the canonical pattern (when an alias is required)
     *  - app exists and has a manifest
     *
     * Returns the resolved Manifest or sends a `wp_send_json_error` and
     * exits. Tests trap the WPDieException raised by wp_send_json_error.
     */
    private static function require_secret_ajax_context(bool $require_alias = true): ?array {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['code' => 'forbidden', 'message' => 'manage_options required'], 403);
        }
        // app_id must be read before check_ajax_referer because the nonce action is keyed to the app; nonce verification follows immediately on the next line.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by check_ajax_referer() on the next statement, which includes app_id in the action
        $app_id = isset($_POST['app_id']) ? sanitize_key(wp_unslash((string) $_POST['app_id'])) : '';
        if ($app_id === '') {
            wp_send_json_error(['code' => 'missing_app_id', 'message' => 'app_id is required'], 400);
        }
        check_ajax_referer(self::secret_nonce_action($app_id), 'nonce');

        $alias = '';
        if ($require_alias) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- alias is immediately validated against a strict regex; sanitize_text_field would lowercase and mangle uppercase-only aliases
            $alias = isset($_POST['alias']) ? (string) wp_unslash((string) $_POST['alias']) : '';
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $alias)) {
                wp_send_json_error(['code' => 'invalid_alias', 'message' => 'alias must match ^[A-Z][A-Z0-9_]{0,63}$'], 422);
            }
        }

        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post instanceof \WP_Post) {
            wp_send_json_error(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw)) {
            wp_send_json_error(['code' => 'not_found', 'message' => 'app manifest missing'], 404);
        }
        try {
            $manifest = Manifest::from_array_unchecked($raw);
        } catch (\Throwable $e) {
            wp_send_json_error(['code' => 'invalid_manifest', 'message' => $e->getMessage()], 500);
        }
        return ['app_id' => $app_id, 'alias' => $alias, 'manifest' => $manifest];
    }

    /**
     * POST admin-ajax `dsgo_apps_secret_set` — write a per-app secret value
     * into the sodium-encrypted vault. Verifies the alias is declared in the
     * manifest's `secrets[]` block before persisting; rejects unknown aliases
     * with `unknown_alias` so a typo doesn't silently leak into the vault.
     */
    public static function ajax_secret_set(): void {
        $ctx = self::require_secret_ajax_context();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified inside require_secret_ajax_context() via check_ajax_referer(); secret value stored verbatim so sanitize_text_field does not corrupt passwords/tokens
        $value = isset($_POST['value']) ? (string) wp_unslash((string) $_POST['value']) : '';
        if ($value === '') {
            wp_send_json_error(['code' => 'empty_value', 'message' => 'value must be non-empty'], 422);
        }
        $declared = array_column($ctx['manifest']->secrets, 'alias');
        if (!in_array($ctx['alias'], $declared, true)) {
            wp_send_json_error([
                'code' => 'unknown_alias',
                'message' => sprintf('alias "%s" is not declared in manifest.secrets', $ctx['alias']),
            ], 422);
        }
        try {
            Secret_Vault::set($ctx['app_id'], $ctx['alias'], $value);
        } catch (\Throwable $e) {
            wp_send_json_error(['code' => 'vault_error', 'message' => $e->getMessage()], 500);
        }
        wp_send_json_success(['ok' => true]);
    }

    /**
     * POST admin-ajax `dsgo_apps_secret_clear` — remove a per-app secret.
     * No-op when the alias is unset; never returns a value either way.
     */
    public static function ajax_secret_clear(): void {
        $ctx = self::require_secret_ajax_context();
        Secret_Vault::delete($ctx['app_id'], $ctx['alias']);
        wp_send_json_success(['ok' => true]);
    }

    /**
     * POST admin-ajax `dsgo_apps_http_test` — fire one fetch through the
     * proxy bridge against the manifest's `http.test_endpoint`. Returns
     * the bridge's success/failure shape unchanged. Admins use this from
     * the Secrets tab to verify allowlist + secret wiring without writing
     * app code.
     *
     * Returns 404 (via wp_send_json_error) if the manifest has no
     * `http.test_endpoint` declared, so the UI button stays disabled
     * unless the author opted in.
     */
    public static function ajax_http_test(): void {
        $ctx = self::require_secret_ajax_context(false /* alias not required */);
        $manifest = $ctx['manifest'];
        if ($manifest->http_test_endpoint === null) {
            wp_send_json_error([
                'code' => 'no_test_endpoint',
                'message' => 'manifest has no http.test_endpoint declared',
            ], 404);
        }
        $result = Http_Proxy_Bridge::fetch($manifest, $manifest->http_test_endpoint, ['method' => 'GET']);
        if (isset($result->ok) && $result->ok === true) {
            wp_send_json_success([
                'ok'      => true,
                'status'  => $result->status,
                'headers' => $result->headers,
                'body'    => $result->body,
            ]);
        }
        $body = [
            'code'    => (string) ($result->error_code ?? 'http_network_error'),
            'message' => (string) ($result->message ?? ''),
        ];
        if (isset($result->retry_after_seconds)) {
            $body['retry_after_seconds'] = (int) $result->retry_after_seconds;
        }
        wp_send_json_error($body, 502);
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
     * Per-app nonce action for the Cron + Webhooks admin tabs. Keyed
     * by app id so a leaked nonce can't be replayed against a sibling
     * app. Separate from the Secrets-tab nonce so the two surfaces'
     * blast radii stay independent.
     */
    private static function cron_webhooks_nonce_action(string $app_id): string {
        return 'dsgo_apps_cron_webhooks_nonce_' . $app_id;
    }

    /**
     * Common gate for the Cron + Webhooks admin-ajax handlers:
     *  - manage_options
     *  - per-app nonce
     *  - app exists and has a parseable manifest
     *
     * Returns the resolved (app_id, Manifest) tuple, or wp_send_json_errors
     * + dies. Mirrors require_secret_ajax_context but writes its own
     * nonce action so the two admin surfaces can't share tokens.
     *
     * @return array{app_id:string, manifest:Manifest}|null
     */
    private static function require_cron_webhooks_ajax_context(): ?array {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['code' => 'forbidden', 'message' => 'manage_options required'], 403);
        }
        // app_id must be read before check_ajax_referer because the nonce action is keyed to the app; nonce verification follows immediately.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next statement
        $app_id = isset($_POST['app_id']) ? sanitize_key(wp_unslash((string) $_POST['app_id'])) : '';
        if ($app_id === '') {
            wp_send_json_error(['code' => 'missing_app_id', 'message' => 'app_id is required'], 400);
        }
        check_ajax_referer(self::cron_webhooks_nonce_action($app_id), 'nonce');

        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post instanceof \WP_Post) {
            wp_send_json_error(['code' => 'not_found', 'message' => 'app not found'], 404);
        }
        $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw)) {
            wp_send_json_error(['code' => 'not_found', 'message' => 'app manifest missing'], 404);
        }
        try {
            $manifest = Manifest::from_array_unchecked($raw);
        } catch (\Throwable $e) {
            wp_send_json_error(['code' => 'invalid_manifest', 'message' => $e->getMessage()], 500);
        }
        return ['app_id' => $app_id, 'manifest' => $manifest];
    }

    /**
     * Read-side accessor used by the cron/webhooks tab templates to
     * mint a fresh nonce for the admin-ajax surface. Lives here so the
     * nonce-action helper has a single definition.
     */
    public static function cron_webhooks_nonce(string $app_id): string {
        return wp_create_nonce(self::cron_webhooks_nonce_action($app_id));
    }

    /**
     * POST admin-ajax `dsgo_apps_cron_run_now` — fire a scheduled
     * job immediately, regardless of its WP-cron schedule. Useful for
     * operators verifying a newly installed cron without waiting for
     * the next tick.
     *
     * Resolves the job_id against the manifest's `scheduled.jobs[]`,
     * then calls CronDispatcher::run() inline. The dispatcher writes
     * its own audit log row; we return that row so the JS can render
     * the outcome inline without a second round-trip.
     */
    public static function ajax_cron_run_now(): void {
        $ctx = self::require_cron_webhooks_ajax_context();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to regex below
        $job_id = isset($_POST['job_id']) ? (string) wp_unslash((string) $_POST['job_id']) : '';
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/', $job_id)) {
            wp_send_json_error(['code' => 'invalid_job_id', 'message' => 'job_id must match ^[a-z][a-z0-9-]{0,63}$'], 422);
        }
        $job = null;
        foreach ($ctx['manifest']->scheduled_jobs() as $entry) {
            if (($entry['id'] ?? null) === $job_id) {
                $job = $entry;
                break;
            }
        }
        if ($job === null) {
            wp_send_json_error(['code' => 'job_not_found', 'message' => 'no job with that id'], 404);
        }

        CronDispatcher::run($ctx['app_id'], $job_id, (string) $job['ability']);

        // The dispatcher just wrote one CronLog row — read it back so
        // the JS can show the outcome inline.
        $rows = CronLog::query($ctx['app_id'], ['job_id' => $job_id, 'per_page' => 1]);
        wp_send_json_success([
            'ok'  => true,
            'log' => $rows[0] ?? null,
        ]);
    }

    /**
     * POST admin-ajax `dsgo_apps_webhook_send_test` — fabricate a
     * signed test payload and run it through the full WebhookHandler
     * pipeline so operators can confirm an endpoint is wired
     * correctly without leaving wp-admin.
     *
     * The handler resolves the endpoint config from the manifest,
     * pulls the stored secret from the per-app vault, and computes
     * the correct headers for the declared auth scheme. The fully-
     * signed request is then dispatched through WebhookHandler::handle
     * so every step the production receiver runs (rate limit, auth,
     * idempotency, dispatch, log) is exercised. The handler's
     * WP_REST_Response (status + body) is returned to the JS verbatim.
     */
    public static function ajax_webhook_send_test(): void {
        $ctx = self::require_cron_webhooks_ajax_context();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to regex below
        $endpoint_id = isset($_POST['endpoint_id']) ? (string) wp_unslash((string) $_POST['endpoint_id']) : '';
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/', $endpoint_id)) {
            wp_send_json_error(['code' => 'invalid_endpoint_id', 'message' => 'endpoint_id must match ^[a-z][a-z0-9-]{0,63}$'], 422);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- body is the verbatim payload bytes; sanitize_text_field would corrupt JSON
        $body = isset($_POST['body']) ? (string) wp_unslash((string) $_POST['body']) : '';

        $endpoint = null;
        foreach ($ctx['manifest']->webhook_endpoints() as $entry) {
            if (($entry['id'] ?? null) === $endpoint_id) {
                $endpoint = $entry;
                break;
            }
        }
        if ($endpoint === null) {
            wp_send_json_error(['code' => 'endpoint_not_found', 'message' => 'no endpoint with that id'], 404);
        }

        // Look up the configured secret. If it isn't set, return the
        // same error code the production pipeline would surface so the
        // operator gets a consistent signal.
        $auth   = $endpoint['auth'] ?? [];
        $alias  = (string) ($auth['secret_alias'] ?? '');
        $secret = $alias !== '' ? Secret_Vault::get($ctx['app_id'], $alias) : null;
        if ($secret === null) {
            wp_send_json_error([
                'code'    => 'webhook_secret_not_set',
                'message' => 'Webhook secret is not configured. Set it in the Secrets tab.',
            ], 503);
        }

        // Compute the signed headers for the declared scheme.
        $headers = self::sign_test_webhook_headers($auth, $body, $secret);

        $req = new \WP_REST_Request('POST', '/dsgo/v1/webhooks/' . $ctx['app_id'] . '/' . $endpoint_id);
        $req->set_body($body);
        $req->set_header('content-type', 'application/json');
        foreach ($headers as $name => $value) {
            $req->set_header($name, $value);
        }

        $response = WebhookHandler::handle($req, $ctx['app_id'], $endpoint_id);
        wp_send_json_success([
            'ok'     => $response->get_status() >= 200 && $response->get_status() < 300,
            'status' => $response->get_status(),
            'body'   => $response->get_data(),
        ]);
    }

    /**
     * Compute the headers a real producer would send for the declared
     * auth scheme. Used by the test-payload handler so the dispatch
     * exercises the production auth verifier rather than bypassing it.
     *
     * @param array<string, mixed> $auth
     * @return array<string, string>
     */
    private static function sign_test_webhook_headers(array $auth, string $body, string $secret): array {
        $type   = (string) ($auth['type']   ?? '');
        $scheme = (string) ($auth['scheme'] ?? '');
        $ts     = time();
        switch ($type === 'hmac-sha256' ? $scheme : $type) {
            case 'stripe':
                $sig = hash_hmac('sha256', "{$ts}.{$body}", $secret);
                return ['stripe-signature' => "t={$ts},v1={$sig}"];
            case 'github':
                return ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret)];
            case 'slack':
                $sig = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);
                return [
                    'x-slack-request-timestamp' => (string) $ts,
                    'x-slack-signature'         => $sig,
                ];
            case 'generic':
                return ['x-webhook-signature' => hash_hmac('sha256', $body, $secret)];
            case 'bearer':
                return ['authorization' => 'Bearer ' . $secret];
            default:
                return [];
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
