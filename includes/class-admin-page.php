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

    public const MENU_SLUG = 'dsgo-apps';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_notices', [self::class, 'maybe_render_reading_notice']);
        add_action('admin_notices', [self::class, 'maybe_render_cap_notice']);
    }

    /**
     * Render an "at the Lite cap" notice on the apps-list admin page when
     * the cap is in force and the site has reached it. Pro lifts the cap
     * via the `dsgo_apps_lite_app_cap` filter; when lifted, this notice
     * never renders (Installer::lite_app_cap returns null).
     */
    public static function maybe_render_cap_notice(): void {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'toplevel_page_' . self::MENU_SLUG) return;

        $cap = Installer::lite_app_cap();
        if ($cap === null) return;
        if (Installer::count_published_apps() < $cap) return;

        $upgrade_url = admin_url('admin.php?page=' . ProUpsell::MENU_SLUG);
        ?>
        <div class="notice notice-info">
            <p>
                <strong>
                    <?php
                    /* translators: %d: number of allowed active apps */
                    echo esc_html(sprintf(
                        _n(
                            'You\'ve reached the Free version\'s limit of %d active app.',
                            'You\'ve reached the Free version\'s limit of %d active apps.',
                            $cap,
                            'dsgo-apps'
                        ),
                        $cap,
                    ));
                    ?>
                </strong>
                <?php esc_html_e('Remove an existing app to install another, or upgrade to Pro for unlimited apps + Riff (the in-admin AI app builder).', 'dsgo-apps'); ?>
                <a href="<?php echo esc_url($upgrade_url); ?>">
                    <?php esc_html_e('Meet Riff →', 'dsgo-apps'); ?>
                </a>
            </p>
        </div>
        <?php
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
                <strong><?php esc_html_e('Your site home is a DSGo App.', 'dsgo-apps'); ?></strong>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: 1: app name, 2: manage URL */
                        __('"%1$s" is rendering at the site root. The "Your homepage displays" choice below applies only after you <a href="%2$s">step the app down</a>.', 'dsgo-apps'),
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
        $svg = @file_get_contents(DSGO_APPS_PATH . 'assets/admin/menu-icon.svg');
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-screenoptions';
        }
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function register_menu(): void {
        add_menu_page(
            __('DSGo Apps', 'dsgo-apps'),
            __('DSGo Apps', 'dsgo-apps'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
            self::menu_icon_data_uri(),
            26,
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Apps', 'dsgo-apps'),
            __('Apps', 'dsgo-apps'),
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

        wp_enqueue_style('dsgo-admin-page', $css, [], $ver);
        wp_enqueue_script('dsgo-admin-page', $js, ['wp-api-fetch', 'wp-i18n'], $ver, true);
        wp_set_script_translations('dsgo-admin-page', 'dsgo-apps');
        wp_localize_script('dsgo-admin-page', 'DSGoAdmin', [
            'restRoot'         => esc_url_raw(rest_url('dsgo/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'siteName'         => (string) get_bloginfo('name'),
            'siteUrl'          => home_url('/'),
            'urlPrefix'        => Settings::get_url_prefix(),
            'maxUploadBytes'   => Bundle::MAX_TOTAL_BYTES,
            'maxFileCount'     => Bundle::MAX_FILE_COUNT,
            'docsUrl'          => 'https://github.com/designsetgo/apps#readme',
            'settingsUrl'      => admin_url('admin.php?page=dsgo-apps-settings'),
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

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
                    echo esc_html((string) apply_filters('dsgo_apps_brand_name', __('DesignSetGo', 'dsgo-apps')));
                ?></p>
                <h1 class="dsgo-admin__title">
                    <?php esc_html_e('Apps', 'dsgo-apps'); ?>
                </h1>
                <p class="dsgo-admin__lede">
                    <?php esc_html_e('Sandboxed mini-apps with a permissioned bridge to your site’s data. Drop in a bundle, or deploy from your terminal.', 'dsgo-apps'); ?>
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

                // Small "What's in Pro?" card. Self-suppresses when the cap
                // filter has been lifted (i.e. Pro is active).
                ProUpsell::render_apps_list_pro_card();
                ?>
            </header>

            <div class="dsgo-admin__layout">
                <section class="dsgo-card dsgo-card--list" aria-labelledby="dsgo-list-heading">
                    <header class="dsgo-card__header dsgo-card__header--list">
                        <div class="dsgo-card__header-text">
                            <h2 id="dsgo-list-heading" class="dsgo-card__title"><?php esc_html_e('Installed apps', 'dsgo-apps'); ?></h2>
                            <p class="dsgo-card__subtitle" data-dsgo-list-subtitle><?php esc_html_e('Loading…', 'dsgo-apps'); ?></p>
                        </div>
                        <button type="button" class="dsgo-install-toggle" data-dsgo-install-toggle
                                aria-expanded="false" aria-controls="dsgo-install-panel">
                            <span aria-hidden="true" class="dsgo-install-toggle__icon">
                                <svg viewBox="0 0 12 12" width="12" height="12" fill="none"
                                     stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                    <path d="M6 1.5v9M1.5 6h9" />
                                </svg>
                            </span>
                            <span class="dsgo-install-toggle__label"><?php esc_html_e('Install another app', 'dsgo-apps'); ?></span>
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
                        <h2 id="dsgo-install-heading" class="dsgo-card__title"><?php esc_html_e('Install an app', 'dsgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle" data-dsgo-card-subtitle><?php esc_html_e('Upload a packaged bundle (.zip) containing a dsgo-app.json manifest.', 'dsgo-apps'); ?></p>
                    </header>

                    <div class="dsgo-tabs" role="tablist" aria-label="<?php esc_attr_e('Install method', 'dsgo-apps'); ?>">
                        <button type="button" class="dsgo-tab is-active" role="tab"
                                aria-selected="true" aria-controls="dsgo-panel-upload"
                                id="dsgo-tab-upload" data-dsgo-tab="upload">
                            <?php esc_html_e('Upload bundle', 'dsgo-apps'); ?>
                        </button>
                        <button type="button" class="dsgo-tab" role="tab"
                                aria-selected="false" aria-controls="dsgo-panel-html"
                                id="dsgo-tab-html" data-dsgo-tab="html">
                            <?php esc_html_e('Upload artifact', 'dsgo-apps'); ?>
                        </button>
                    </div>

                    <div id="dsgo-panel-upload" role="tabpanel" aria-labelledby="dsgo-tab-upload"
                         class="dsgo-panel" data-dsgo-panel="upload">
                        <div class="dsgo-dropzone" data-dsgo-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose a bundle zip to install', 'dsgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 16V4" />
                                    <path d="m6 10 6-6 6 6" />
                                    <path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary"><?php esc_html_e('Drop a bundle here', 'dsgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'dsgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-pick><?php esc_html_e('choose a file', 'dsgo-apps'); ?></button>
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
                                __('Drop a single <code>.html</code> file (a Claude Artifact, a single-file game, or any standalone page) or a <code>.zip</code> of a static export (a Claude Design bundle, a built static site without a manifest) and we’ll wrap it in a sandboxed app.', 'dsgo-apps'),
                                ['code' => []],
                            );
                            ?>
                        </p>

                        <div class="dsgo-dropzone" data-dsgo-html-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose an HTML or zip file to install', 'dsgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 3v5h5" />
                                    <path d="M19 8v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary" data-dsgo-html-primary><?php esc_html_e('Drop an HTML or zip file here', 'dsgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'dsgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-html-pick><?php esc_html_e('choose a file', 'dsgo-apps'); ?></button>
                                </p>
                            </div>
                            <input type="file" accept=".html,.htm,text/html,.zip,application/zip" data-dsgo-html-input hidden>
                        </div>

                        <div class="dsgo-field-row">
                            <div class="dsgo-field">
                                <label for="dsgo-id-input" class="dsgo-field__label"><?php esc_html_e('App ID', 'dsgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-id-input" data-dsgo-id
                                       autocomplete="off" maxlength="64">
                                <p class="dsgo-field__hint"><?php esc_html_e('Lowercase letters, numbers, hyphens. 3–64 chars.', 'dsgo-apps'); ?></p>
                            </div>
                            <div class="dsgo-field">
                                <label for="dsgo-name-input" class="dsgo-field__label"><?php esc_html_e('Display name', 'dsgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-name-input" data-dsgo-name
                                       autocomplete="off" maxlength="80">
                                <p class="dsgo-field__hint"><?php esc_html_e('Shown in the apps list and tab title.', 'dsgo-apps'); ?></p>
                            </div>
                        </div>

                        <div class="dsgo-actions">
                            <button type="button" class="button button-primary" data-dsgo-html-submit disabled>
                                <?php esc_html_e('Install artifact', 'dsgo-apps'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="dsgo-status" data-dsgo-status hidden>
                        <div class="dsgo-status__bar"><div class="dsgo-status__fill" data-dsgo-progress></div></div>
                        <p class="dsgo-status__text" data-dsgo-status-text></p>
                    </div>

                    <details class="dsgo-altpath" data-dsgo-starter-details>
                        <summary><?php esc_html_e('Or install the bundled starter', 'dsgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('A hand-crafted multi-page demo (<code>dsgo-starter</code>) with bridge examples. Installs in one click &mdash; no terminal, no build step. Re-installing replaces the existing copy.', 'dsgo-apps'),
                                ['code' => []],
                            );
                            ?>
                        </p>
                        <div class="dsgo-actions">
                            <button type="button" class="button button-primary" data-dsgo-starter-install>
                                <?php esc_html_e('Install starter app', 'dsgo-apps'); ?>
                            </button>
                        </div>
                    </details>

                    <details class="dsgo-altpath">
                        <summary><?php esc_html_e('Or vibe-code it in your favorite IDE', 'dsgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('Build your app with <strong>Claude Code</strong>, <strong>Cursor</strong>, <strong>Codex</strong>, or any AI coding IDE &mdash; then ship it to this site with one command.', 'dsgo-apps'),
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
                                __('The CLI uses an <strong>Application Password</strong> generated from your WordPress profile. Generate one at <a href="profile.php#application-passwords-section">your profile page</a>.', 'dsgo-apps'),
                                ['strong' => [], 'a' => ['href' => true]],
                            );
                            ?>
                        </p>
                    </details>
                </section>
            </div>

            <footer class="dsgo-admin__footnote">
                <span><?php esc_html_e('URL prefix', 'dsgo-apps'); ?>: <code data-dsgo-prefix><?php echo esc_html(Settings::get_url_prefix()); ?></code></span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsgo-apps-settings')); ?>"><?php esc_html_e('Configure routing →', 'dsgo-apps'); ?></a>
            </footer>
        </div>

        <template data-dsgo-row-template>
            <li class="dsgo-applist__row">
                <div class="dsgo-applist__main">
                    <div class="dsgo-applist__title-row">
                        <span class="dsgo-applist__title"></span>
                        <span class="dsgo-applist__home-badge" hidden>
                            <span aria-hidden="true">⌂</span>
                            <?php esc_html_e('Site home', 'dsgo-apps'); ?>
                        </span>
                    </div>
                    <div class="dsgo-applist__meta"></div>
                </div>
                <a class="dsgo-applist__url" target="_blank" rel="noopener noreferrer"></a>
                <div class="dsgo-applist__actions">
                    <button type="button" class="dsgo-applist__home" data-dsgo-home></button>
                    <button type="button" class="dsgo-applist__delete" data-dsgo-delete aria-label=""><?php esc_html_e('Delete', 'dsgo-apps'); ?></button>
                </div>
            </li>
        </template>

        <template data-dsgo-consent-template>
            <div class="dsgo-consent" role="region">
                <h3 class="dsgo-consent__title" data-dsgo-consent-title></h3>
                <div class="dsgo-consent__body" data-dsgo-consent-body></div>
                <div class="dsgo-consent__actions">
                    <button type="button" class="dsgo-consent__cancel" data-dsgo-consent-cancel><?php esc_html_e('Cancel', 'dsgo-apps'); ?></button>
                    <button type="button" class="dsgo-consent__confirm" data-dsgo-consent-confirm></button>
                </div>
            </div>
        </template>
        <?php
    }
}
