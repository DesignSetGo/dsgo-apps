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
            26,
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
        $js       = plugins_url('assets/admin/admin-page.js', DSGO_APPS_FILE);
        $css_path = DSGO_APPS_PATH . 'assets/admin/admin-page.css';
        $ver      = file_exists($css_path) ? (string) filemtime($css_path) : DSGO_APPS_VERSION;

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
        wp_enqueue_script('dsgo-admin-page', $js, ['wp-api-fetch', 'wp-i18n'], $ver, true);
        wp_set_script_translations('dsgo-admin-page', 'designsetgo-apps');
        wp_localize_script('dsgo-admin-page', 'DSGoAdmin', [
            'restRoot'         => esc_url_raw(rest_url('dsgo/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'siteName'         => (string) get_bloginfo('name'),
            'siteUrl'          => home_url('/'),
            'urlPrefix'        => Settings::get_url_prefix(),
            'maxUploadBytes'   => Bundle::MAX_TOTAL_BYTES,
            'maxFileCount'     => Bundle::MAX_FILE_COUNT,
            'docsUrl'          => 'https://designsetgo.dev/docs',
            'settingsUrl'      => admin_url('admin.php?page=designsetgo-apps-settings'),
            'pricingUrl'       => (string) apply_filters('dsgo_apps_pro_pricing_url', 'https://designsetgo.dev/pricing'),
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
        ?>
        <div class="dsgo-admin <?php echo esc_attr($initial_state); ?>" data-dsgo-admin>
            <header class="dsgo-admin__hero">
                <p class="dsgo-admin__eyebrow"><?php
                    /**
                     * Filter the brand-name eyebrow on the apps-list admin page.
                     * Pro's white-label feature (Agency tier) replaces this with a
                     * customer-configured brand name; free returns the default.
                     */
                    echo esc_html((string) apply_filters('dsgo_apps_brand_name', __('DesignSetGo', 'designsetgo-apps')));
                ?></p>
                <h1 class="dsgo-admin__title">
                    <?php esc_html_e('Apps', 'designsetgo-apps'); ?>
                </h1>
                <p class="dsgo-admin__lede">
                    <?php esc_html_e('Sandboxed mini-apps with a permissioned bridge to your site’s data. Drop in a bundle, or deploy from your terminal.', 'designsetgo-apps'); ?>
                </p>
                <?php
                /**
                 * Single extension point for Pro (or any third party) to inject
                 * page-level actions into the apps-list admin surface. Free
                 * intentionally has zero references to Pro — Pro registers a
                 * listener on this hook from its own bootstrap.
                 *
                 * @param array{page:string} $context
                 */
                do_action('dsgo_apps_admin_actions', ['page' => 'apps-list']);
                ?>
            </header>

            <div class="dsgo-admin__layout">
                <section class="dsgo-card dsgo-card--list" aria-labelledby="dsgo-list-heading">
                    <header class="dsgo-card__header dsgo-card__header--list">
                        <div class="dsgo-card__header-text">
                            <h2 id="dsgo-list-heading" class="dsgo-card__title"><?php esc_html_e('Installed apps', 'designsetgo-apps'); ?></h2>
                            <p class="dsgo-card__subtitle" data-dsgo-list-subtitle><?php esc_html_e('Loading…', 'designsetgo-apps'); ?></p>
                        </div>
                        <button type="button" class="dsgo-install-toggle" data-dsgo-install-toggle
                                aria-expanded="false" aria-controls="dsgo-install-panel">
                            <span aria-hidden="true" class="dsgo-install-toggle__icon">
                                <svg viewBox="0 0 12 12" width="12" height="12" fill="none"
                                     stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                    <path d="M6 1.5v9M1.5 6h9" />
                                </svg>
                            </span>
                            <span class="dsgo-install-toggle__label"><?php esc_html_e('Install another app', 'designsetgo-apps'); ?></span>
                        </button>
                    </header>
                    <ul class="dsgo-applist" data-dsgo-list role="list" aria-busy="true">
                        <li class="dsgo-applist__skel"></li>
                        <li class="dsgo-applist__skel"></li>
                    </ul>
                </section>

                <section class="dsgo-card dsgo-card--install" id="dsgo-install-panel"
                         data-dsgo-install-panel aria-labelledby="dsgo-install-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-install-heading" class="dsgo-card__title"><?php esc_html_e('Install an app', 'designsetgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle" data-dsgo-card-subtitle><?php esc_html_e('Upload a packaged bundle (.zip) containing a dsgo-app.json manifest.', 'designsetgo-apps'); ?></p>
                    </header>

                    <div class="dsgo-tabs" role="tablist" aria-label="<?php esc_attr_e('Install method', 'designsetgo-apps'); ?>">
                        <button type="button" class="dsgo-tab is-active" role="tab"
                                aria-selected="true" aria-controls="dsgo-panel-upload"
                                id="dsgo-tab-upload" data-dsgo-tab="upload">
                            <?php esc_html_e('Upload bundle', 'designsetgo-apps'); ?>
                        </button>
                        <button type="button" class="dsgo-tab" role="tab"
                                aria-selected="false" aria-controls="dsgo-panel-html"
                                id="dsgo-tab-html" data-dsgo-tab="html">
                            <?php esc_html_e('Upload artifact', 'designsetgo-apps'); ?>
                        </button>
                    </div>

                    <div id="dsgo-panel-upload" role="tabpanel" aria-labelledby="dsgo-tab-upload"
                         class="dsgo-panel" data-dsgo-panel="upload">
                        <div class="dsgo-dropzone" data-dsgo-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose a bundle zip to install', 'designsetgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 16V4" />
                                    <path d="m6 10 6-6 6 6" />
                                    <path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary"><?php esc_html_e('Drop a bundle here', 'designsetgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'designsetgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-pick><?php esc_html_e('choose a file', 'designsetgo-apps'); ?></button>
                                </p>
                            </div>
                            <input type="file" accept=".zip,application/zip" data-dsgo-input hidden>
                        </div>
                    </div>

                    <div id="dsgo-panel-html" role="tabpanel" aria-labelledby="dsgo-tab-html"
                         class="dsgo-panel" data-dsgo-panel="html" hidden>
                        <p class="dsgo-panel__lede">
                            <?php
                            echo wp_kses(
                                __('Drop a single <code>.html</code> file (a Claude Artifact, a single-file game, or any standalone page) or a <code>.zip</code> of a static export (a Claude Design bundle, a built static site without a manifest) and we’ll wrap it in a sandboxed app.', 'designsetgo-apps'),
                                ['code' => []],
                            );
                            ?>
                        </p>

                        <div class="dsgo-dropzone" data-dsgo-html-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose an HTML or zip file to install', 'designsetgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 3v5h5" />
                                    <path d="M19 8v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary" data-dsgo-html-primary><?php esc_html_e('Drop an HTML or zip file here', 'designsetgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'designsetgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-html-pick><?php esc_html_e('choose a file', 'designsetgo-apps'); ?></button>
                                </p>
                            </div>
                            <input type="file" accept=".html,.htm,text/html,.zip,application/zip" data-dsgo-html-input hidden>
                        </div>

                        <div class="dsgo-field-row">
                            <div class="dsgo-field">
                                <label for="dsgo-id-input" class="dsgo-field__label"><?php esc_html_e('App ID', 'designsetgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-id-input" data-dsgo-id
                                       autocomplete="off" maxlength="64">
                                <p class="dsgo-field__hint"><?php esc_html_e('Lowercase letters, numbers, hyphens. 3–64 chars.', 'designsetgo-apps'); ?></p>
                            </div>
                            <div class="dsgo-field">
                                <label for="dsgo-name-input" class="dsgo-field__label"><?php esc_html_e('Display name', 'designsetgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-name-input" data-dsgo-name
                                       autocomplete="off" maxlength="80">
                                <p class="dsgo-field__hint"><?php esc_html_e('Shown in the apps list and tab title.', 'designsetgo-apps'); ?></p>
                            </div>
                        </div>

                        <div class="dsgo-actions">
                            <button type="button" class="button button-primary" data-dsgo-html-submit disabled>
                                <?php esc_html_e('Install artifact', 'designsetgo-apps'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="dsgo-status" data-dsgo-status hidden>
                        <div class="dsgo-status__bar"><div class="dsgo-status__fill" data-dsgo-progress></div></div>
                        <p class="dsgo-status__text" data-dsgo-status-text></p>
                    </div>

                    <details class="dsgo-altpath" data-dsgo-starter-details>
                        <summary><?php esc_html_e('Or install the bundled starter', 'designsetgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('A hand-crafted multi-page demo (<code>dsgo-starter</code>) with bridge examples. Installs in one click &mdash; no terminal, no build step. Re-installing replaces the existing copy.', 'designsetgo-apps'),
                                ['code' => []],
                            );
                            ?>
                        </p>
                        <div class="dsgo-actions">
                            <button type="button" class="button button-primary" data-dsgo-starter-install>
                                <?php esc_html_e('Install starter app', 'designsetgo-apps'); ?>
                            </button>
                        </div>
                    </details>

                    <details class="dsgo-altpath">
                        <summary><?php esc_html_e('Or vibe-code it in your favorite IDE', 'designsetgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('Build your app with <strong>Claude Code</strong>, <strong>Cursor</strong>, <strong>Codex</strong>, or any AI coding IDE &mdash; then ship it to this site with one command.', 'designsetgo-apps'),
                                ['strong' => []],
                            );
                            ?>
                        </p>
                        <pre class="dsgo-code"><code>npx designsetgo apps init my-app
cd my-app
npx designsetgo apps login
npx designsetgo apps deploy --build</code></pre>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('The CLI uses an <strong>Application Password</strong> generated from your WordPress profile. Generate one at <a href="profile.php#application-passwords-section">your profile page</a>.', 'designsetgo-apps'),
                                ['strong' => [], 'a' => ['href' => true]],
                            );
                            ?>
                        </p>
                    </details>
                </section>
            </div>

            <footer class="dsgo-admin__footnote">
                <span><?php esc_html_e('URL prefix', 'designsetgo-apps'); ?>: <code data-dsgo-prefix><?php echo esc_html(Settings::get_url_prefix()); ?></code></span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=designsetgo-apps-settings')); ?>"><?php esc_html_e('Configure routing →', 'designsetgo-apps'); ?></a>
            </footer>
        </div>

        <template data-dsgo-row-template>
            <li class="dsgo-applist__row">
                <div class="dsgo-applist__main">
                    <div class="dsgo-applist__title-row">
                        <span class="dsgo-applist__title"></span>
                        <span class="dsgo-applist__home-badge" hidden>
                            <span aria-hidden="true">⌂</span>
                            <?php esc_html_e('Site home', 'designsetgo-apps'); ?>
                        </span>
                    </div>
                    <div class="dsgo-applist__meta"></div>
                </div>
                <a class="dsgo-applist__url" target="_blank" rel="noopener noreferrer"></a>
                <div class="dsgo-applist__actions">
                    <button type="button" class="dsgo-applist__home" data-dsgo-home></button>
                    <button type="button" class="dsgo-applist__delete" data-dsgo-delete aria-label=""><?php esc_html_e('Delete', 'designsetgo-apps'); ?></button>
                </div>
            </li>
        </template>

        <template data-dsgo-consent-template>
            <div class="dsgo-consent" role="region">
                <h3 class="dsgo-consent__title" data-dsgo-consent-title></h3>
                <div class="dsgo-consent__body" data-dsgo-consent-body></div>
                <div class="dsgo-consent__actions">
                    <button type="button" class="dsgo-consent__cancel" data-dsgo-consent-cancel><?php esc_html_e('Cancel', 'designsetgo-apps'); ?></button>
                    <button type="button" class="dsgo-consent__confirm" data-dsgo-consent-confirm></button>
                </div>
            </div>
        </template>
        <?php
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
                if ($manifest->scheduled_jobs() !== []) {
                    self::render_per_app_tab_link($app_id, 'cron', __('Cron', 'designsetgo-apps'), $tab);
                }
                if ($manifest->webhook_endpoints() !== []) {
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
                        self::render_cron_tab($manifest);
                        break;
                    case 'webhooks':
                        self::render_webhooks_tab($manifest);
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
        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'app_id'   => $manifest->id,
            'app_name' => $manifest->name,
            'jobs'     => $manifest->scheduled_jobs(),
            'log_rows' => CronLog::query($manifest->id, ['per_page' => 50]),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PATH . 'templates/cron-tab.php';
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
        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'app_id'    => $manifest->id,
            'app_name'  => $manifest->name,
            'endpoints' => $manifest->webhook_endpoints(),
            'log_rows'  => WebhookLog::query($manifest->id, ['per_page' => 50]),
            'pro_gate'  => ProFeatureGate::is_enabled('webhooks'),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PATH . 'templates/webhooks-tab.php';
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
