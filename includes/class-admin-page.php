<?php
/**
 * Top-level admin page: install, list, and manage DSGo apps.
 *
 * Settings → DSGo Apps remains the URL-prefix configuration screen.
 * This page (Tools-style top-level menu) is the install/manage surface and
 * is what every onboarding touchpoint (activation notice, block-editor empty
 * state, readme.txt) points to.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AdminPage {

    public const MENU_SLUG = 'designsetgo-apps';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_notices', [self::class, 'maybe_render_reading_notice']);
    }

    /**
     * On Settings → Reading, show an info notice when a DSGo app is the
     * current site home. The picker itself lives on the DSGo Apps page;
     * this notice exists to (a) explain why WordPress's normal "front page
     * displays" choice has been overridden, and (b) point users to the
     * right management surface.
     */
    public static function maybe_render_reading_notice(): void {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'options-reading') return;
        $home_id = Settings::get_root_app_id();
        if ($home_id === null) return;
        $post = get_page_by_path($home_id, OBJECT, PostType::SLUG);
        if (!$post) return;
        $manage_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e('Your site home is a DSGo App.', 'designsetgo-apps'); ?></strong>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: 1: app name, 2: manage URL */
                        __('"%1$s" is rendering at the site root. The "Your homepage displays" choice below applies only after you <a href="%2$s">step the app down</a>.', 'designsetgo-apps'),
                        esc_html($post->post_title),
                        esc_url($manage_url),
                    ),
                    ['a' => ['href' => true]],
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function menu_icon_data_uri(): string {
        $icon_path = DSGO_APPS_PATH . 'assets/admin/menu-icon.svg';
        if (!is_readable($icon_path)) {
            return 'dashicons-screenoptions';
        }
        $svg = file_get_contents($icon_path);
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-screenoptions';
        }
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function register_menu(): void {
        add_menu_page(
            __('DSGo Apps', 'designsetgo-apps'),
            __('DSGo Apps', 'designsetgo-apps'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
            self::menu_icon_data_uri(),
            29,
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Apps', 'designsetgo-apps'),
            __('Apps', 'designsetgo-apps'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
        );
        // Settings submenu item is added by Settings::register_settings_page,
        // which owns the page callback. Keeping registration there means the
        // "Settings" submenu appears under DSGo Apps with no menu-vs-callback
        // duplication here.
    }

    public static function enqueue_assets(string $hook): void {
        // Only load on our top-level page; matches WP's hook slug for menu pages.
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        $css      = plugins_url('assets/admin/admin-page.css', DSGO_APPS_FILE);
        $css_path = DSGO_APPS_PATH . 'assets/admin/admin-page.css';
        $ver      = file_exists($css_path) ? (string) filemtime($css_path) : DSGO_APPS_VERSION;

        // admin-page.js was split into a small set of plain-IIFE modules that
        // share the `window.DSGoAdmin` namespace. They are enqueued as
        // separate handles with WordPress dependency chaining so the browser
        // loads them in the required order: core → consent → list → install.
        // The localized config + script translations stay on the core handle
        // (`dsgo-admin-page`); the other modules read that object at runtime.
        $js_modules = [
            'dsgo-admin-page'         => ['file' => 'admin-page-core.js',    'deps' => ['wp-api-fetch', 'wp-i18n']],
            'dsgo-admin-page-consent' => ['file' => 'admin-page-consent.js', 'deps' => ['dsgo-admin-page']],
            'dsgo-admin-page-list'    => ['file' => 'admin-page-list.js',    'deps' => ['dsgo-admin-page-consent']],
            'dsgo-admin-page-install' => ['file' => 'admin-page-install.js', 'deps' => ['dsgo-admin-page-list']],
        ];

        // Per-app Secrets tab assets — only enqueue when the URL says we're
        // about to render that tab, so the apps-list page stays lean.
        $is_secrets_tab = isset($_GET['app_id'])   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && sanitize_key(wp_unslash($_GET['tab'] ?? '')) === 'secrets';
        if ($is_secrets_tab) {
            $secrets_css      = plugins_url('assets/admin/secrets-tab.css', DSGO_APPS_FILE);
            $secrets_js       = plugins_url('assets/admin/secrets-tab.js', DSGO_APPS_FILE);
            $secrets_css_path = DSGO_APPS_PATH . 'assets/admin/secrets-tab.css';
            $secrets_ver      = file_exists($secrets_css_path) ? (string) filemtime($secrets_css_path) : DSGO_APPS_VERSION;
            wp_enqueue_style('dsgo-secrets-tab', $secrets_css, [], $secrets_ver);
            wp_enqueue_script('dsgo-secrets-tab', $secrets_js, ['wp-i18n'], $secrets_ver, true);
            wp_set_script_translations('dsgo-secrets-tab', 'designsetgo-apps');
        }

        wp_enqueue_style('dsgo-admin-page', $css, [], $ver);

        foreach ($js_modules as $handle => $module) {
            $module_url  = plugins_url('assets/admin/' . $module['file'], DSGO_APPS_FILE);
            $module_path = DSGO_APPS_PATH . 'assets/admin/' . $module['file'];
            $module_ver  = file_exists($module_path) ? (string) filemtime($module_path) : DSGO_APPS_VERSION;
            wp_enqueue_script($handle, $module_url, $module['deps'], $module_ver, true);
        }

        // Script translations + localized config attach to the core handle;
        // every module reads `window.DSGoAdmin` at runtime.
        wp_set_script_translations('dsgo-admin-page', 'designsetgo-apps');
        wp_localize_script('dsgo-admin-page', 'DSGoAdmin', [
            'restRoot'         => esc_url_raw(rest_url('dsgo/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'siteName'         => (string) get_bloginfo('name'),
            'siteUrl'          => home_url('/'),
            'urlPrefix'        => Settings::get_url_prefix(),
            'maxUploadBytes'   => Bundle::max_total_bytes(),
            'maxFileCount'     => Bundle::MAX_FILE_COUNT,
            'docsUrl'          => 'https://designsetgo.dev/docs',
            'settingsUrl'      => admin_url('admin.php?page=designsetgo-apps-settings'),
            'newPostUrl'       => admin_url('post-new.php?post_type=page'),
            'pricingUrl'       => (string) apply_filters('dsgo_apps_pro_pricing_url', 'https://designsetgo.dev/pricing/'),
            'aiContext'        => [
                'permissions'        => AiContextPack::all_permissions(),
                'defaultPermissions' => AiContextPack::default_permissions(),
                'sections'           => AiContextPack::sections_for_client(),
            ],
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        // Per-app page route: ?app_id=<slug>&tab=<tab>. When ?app_id is set
        // we render a per-app settings surface instead of the apps list.
        // Today the only tab is "secrets" — other tabs (overview, audit log,
        // permissions) come with later phases.
        $route_app_id = isset($_GET['app_id']) ? sanitize_key(wp_unslash((string) $_GET['app_id'])) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($route_app_id !== '') {
            self::render_per_app_page($route_app_id);
            return;
        }

        // Best-effort initial state guess so the layout doesn't flicker between
        // the empty hero and the has-apps view while the REST fetch resolves.
        // JS reconciles after the fetch returns.
        $count_obj      = wp_count_posts(PostType::SLUG);
        $publish_count  = isset($count_obj->publish) ? (int) $count_obj->publish : 0;
        $initial_state  = $publish_count > 0 ? 'dsgo-admin--has-apps' : 'dsgo-admin--empty';

        // The apps-list markup (hero, install card, JS <template> blocks)
        // lives in templates/apps-list.php — same require-a-partial pattern as
        // the per-app admin tabs. render() stays a data-prep + dispatch method.
        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = ['initial_state' => $initial_state];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PATH . 'templates/apps-list.php';
    }

    /**
     * Render the per-app settings surface for `?app_id=<slug>`. Switches on
     * `?tab=` to pick the inner template. Unknown app_id renders an error
     * notice and a back-to-list link rather than 404'ing the wp-admin page.
     */
    private static function render_per_app_page(string $app_id): void {
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            self::render_app_not_found($app_id);
            return;
        }
        $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw)) {
            self::render_app_not_found($app_id);
            return;
        }
        try {
            $manifest = Manifest::from_array_unchecked($raw);
        } catch (\Throwable $e) {
            self::render_app_not_found($app_id);
            return;
        }

        // Default tab. Secrets is the only one today; when overview /
        // audit-log / permissions land, change this default and re-introduce
        // a per-manifest preference if needed.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'secrets';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        ?>
        <div class="wrap dsgo-app-page">
            <h1 class="dsgo-app-page__title">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="dsgo-app-page__back">
                    &larr; <?php esc_html_e('Apps', 'designsetgo-apps'); ?>
                </a>
                &nbsp;/&nbsp;
                <?php echo esc_html($manifest->name); ?>
                <span class="dsgo-app-page__version">v<?php echo esc_html($manifest->version); ?></span>
            </h1>

            <nav class="dsgo-app-page__tabs" role="tablist">
                <?php
                self::render_per_app_tab_link($app_id, 'secrets', __('Secrets', 'designsetgo-apps'), $tab);
                // Cron + Webhooks tabs only appear when the manifest
                // actually declares them — empty tabs are noise.
                if ($manifest->scheduled_jobs() !== [] && class_exists(CronLog::class)) {
                    self::render_per_app_tab_link($app_id, 'cron', __('Cron', 'designsetgo-apps'), $tab);
                }
                if ($manifest->webhook_endpoints() !== [] && class_exists(WebhookLog::class)) {
                    self::render_per_app_tab_link($app_id, 'webhooks', __('Webhooks', 'designsetgo-apps'), $tab);
                }
                ?>
            </nav>

            <div class="dsgo-app-page__panel">
                <?php
                switch ($tab) {
                    case 'secrets':
                        self::render_secrets_tab($manifest);
                        break;
                    case 'cron':
                        class_exists(CronLog::class) ? self::render_cron_tab($manifest) : self::render_pro_runtime_unavailable();
                        break;
                    case 'webhooks':
                        class_exists(WebhookLog::class) ? self::render_webhooks_tab($manifest) : self::render_pro_runtime_unavailable();
                        break;
                    default:
                        echo '<p>' . esc_html__('Unknown tab.', 'designsetgo-apps') . '</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function render_per_app_tab_link(string $app_id, string $slug, string $label, string $current): void {
        $is_active = $current === $slug;
        $url = add_query_arg(
            ['page' => self::MENU_SLUG, 'app_id' => $app_id, 'tab' => $slug],
            admin_url('admin.php'),
        );
        printf(
            '<a href="%s" class="dsgo-app-page__tab%s" role="tab" aria-selected="%s">%s</a>',
            esc_url($url),
            $is_active ? ' is-active' : '',
            $is_active ? 'true' : 'false',
            esc_html($label),
        );
    }

    private static function render_pro_runtime_unavailable(): void {
        printf(
            '<p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_html__('This feature is available in DesignSetGo Apps Pro.', 'designsetgo-apps'),
            esc_url((string) apply_filters('dsgo_apps_pro_pricing_url', 'https://designsetgo.dev/pricing/')),
            esc_html__('View pricing', 'designsetgo-apps'),
        );
    }

    private static function render_app_not_found(string $app_id): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('App not found', 'designsetgo-apps'); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: %s: app id from the URL */
                    esc_html__('No installed app matches the id "%s". It may have been uninstalled.', 'designsetgo-apps'),
                    esc_html($app_id),
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="button">
                    &larr; <?php esc_html_e('Back to Apps', 'designsetgo-apps'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Returns the list of Pro-gated features declared in this manifest that
     * are not currently active on this site. Empty array when every declared
     * feature is either absent or gated open.
     *
     * @param array<string,mixed> $manifest_arr Stored manifest array.
     * @return string[] Internal feature names from ProFeatureGate's canonical set.
     */
    public static function inactive_pro_features_for_manifest(array $manifest_arr): array {
        $inactive = [];
        $declares = [
            'cron'               => !empty($manifest_arr['scheduled']['jobs']),
            'webhooks'           => !empty($manifest_arr['webhooks']['endpoints']),
            'abilities_publish'  => !empty($manifest_arr['abilities']['publishes']),
            'dynamic_routes'     => self::manifest_has_dynamic_route($manifest_arr),
        ];
        foreach ($declares as $feature => $declared) {
            if ($declared && !ProFeatureGate::is_enabled($feature)) {
                $inactive[] = $feature;
            }
        }
        return $inactive;
    }

    private static function manifest_has_dynamic_route(array $manifest_arr): bool {
        foreach (($manifest_arr['routes'] ?? []) as $route) {
            $source = $route['dataset']['source'] ?? null;
            if (!is_string($source) || $source === '') {
                continue;
            }
            if (str_starts_with($source, 'wp:') || str_starts_with($source, 'wc:')) {
                return true;
            }
            // Third-party resolver registered for this source?
            $resolver = \apply_filters('dsgo_apps_dataset_resolver', null, $source);
            if (\is_callable($resolver)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the Secrets tab body. Assembles the $ctx the template expects
     * (declared aliases from manifest + currently-set aliases from the vault
     * + the per-app nonce + the optional test endpoint).
     */
    /**
     * Render the Cron tab body. Shown when the manifest declares at
     * least one scheduled job. Two sections:
     *   - per-job table: id, ability, schedule, next-fire time (from
     *     wp_next_scheduled), execute_php presence.
     *   - paginated CronLog entries: most-recent first.
     *
     * The "Run now" + JS interactivity lands in a follow-up commit.
     */
    private static function render_cron_tab(Manifest $manifest): void {
        // The cron + webhooks admin-ajax surface uses its own per-app
        // nonce action; the JS reads the token off the localized
        // dsgoCronWebhooks global. Both tabs share the same token.
        wp_enqueue_script(
            'dsgo-apps-cron-tab',
            plugins_url('assets/admin/cron-tab.js', DSGO_APPS_PRO_FILE),
            [],
            DSGO_APPS_PRO_VERSION,
            true,
        );
        wp_localize_script('dsgo-apps-cron-tab', 'dsgoCronWebhooks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'appId'   => $manifest->id,
            'nonce'   => RestApi::cron_webhooks_nonce($manifest->id),
        ]);

        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'app_id'   => $manifest->id,
            'app_name' => $manifest->name,
            'jobs'     => $manifest->scheduled_jobs(),
            'log_rows' => CronLog::query($manifest->id, ['per_page' => 50]),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PRO_PATH . 'templates/cron-tab.php';
    }

    /**
     * Render the Webhooks tab body. Shown when the manifest declares
     * at least one webhook endpoint. Two sections:
     *   - per-endpoint table: id, ability, auth scheme, async flag,
     *     callback URL (so operators can copy-paste into Stripe/GitHub).
     *   - paginated WebhookLog entries: most-recent first.
     *
     * The "Send test payload" form + JS lands in a follow-up commit.
     */
    private static function render_webhooks_tab(Manifest $manifest): void {
        wp_enqueue_script(
            'dsgo-apps-webhooks-tab',
            plugins_url('assets/admin/webhooks-tab.js', DSGO_APPS_PRO_FILE),
            [],
            DSGO_APPS_PRO_VERSION,
            true,
        );
        wp_localize_script('dsgo-apps-webhooks-tab', 'dsgoCronWebhooks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'appId'   => $manifest->id,
            'nonce'   => RestApi::cron_webhooks_nonce($manifest->id),
        ]);

        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'app_id'    => $manifest->id,
            'app_name'  => $manifest->name,
            'endpoints' => $manifest->webhook_endpoints(),
            'log_rows'  => WebhookLog::query($manifest->id, ['per_page' => 50]),
            'pro_gate'  => ProFeatureGate::is_enabled('webhooks'),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PRO_PATH . 'templates/webhooks-tab.php';
    }

    private static function render_secrets_tab(Manifest $manifest): void {
        $set_aliases = Secret_Vault::is_available()
            ? Secret_Vault::list_set_aliases($manifest->id)
            : [];

        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'app_id'        => $manifest->id,
            'app_name'      => $manifest->name,
            'secrets'       => $manifest->secrets,
            'required'      => $manifest->required_secrets,
            'set_aliases'   => $set_aliases,
            'test_endpoint' => $manifest->http_test_endpoint,
            'nonce'         => wp_create_nonce('dsgo_apps_secret_nonce_' . $manifest->id),
            'just_installed' => isset($_GET['just_installed']),   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'sodium_ok'     => Secret_Vault::is_available(),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PATH . 'templates/secrets-tab.php';
    }
}
