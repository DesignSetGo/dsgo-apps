<?php
/**
 * Site-level settings for DesignSetGo Apps.
 *
 * Stores the URL prefix used by prefixed-mount apps (default `apps`) and the
 * cached id of the single root-mounted app (if any). Renders an admin page
 * under Settings → DSGo Apps.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class Settings {

    public const OPTION_URL_PREFIX           = 'dsgo_apps_url_prefix';
    public const OPTION_ROOT_APP_ID          = 'dsgo_apps_root_app_id';
    public const OPTION_HARNESS_SHARE_CONTENT = 'dsgo_apps_harness_share_content';

    public const DEFAULT_URL_PREFIX = 'apps';

    /**
     * WP-internal segments and DSGo internals that must never serve as the URL prefix —
     * picking one would shadow core endpoints (admin, REST, sitemaps, feeds).
     */
    public const RESERVED_PREFIXES = [
        'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login',
        'feed', 'comments', 'trackback', 'xmlrpc',
        'dashboard', 'embed', 'index',
        'sitemap', 'sitemap_index',
        'dsgo_app',
    ];

    public static function register(): void {
        register_setting(
            'dsgo_apps_settings',
            self::OPTION_URL_PREFIX,
            [
                'type'              => 'string',
                'description'       => __('URL segment under which DSGo apps are served (e.g. "apps").', 'dsgo-apps'),
                'sanitize_callback' => [self::class, 'sanitize_url_prefix'],
                'default'           => self::DEFAULT_URL_PREFIX,
                'show_in_rest'      => false,
            ],
        );

        register_setting(
            'dsgo_apps_settings',
            Harness_Models::OPTION_TIER_MODELS,
            [
                'type'              => 'array',
                'description'       => __('Per-tier AI model assignments for the harness (routing + main).', 'dsgo-apps'),
                'sanitize_callback' => [self::class, 'sanitize_harness_models'],
                'default'           => ['routing' => '', 'main' => ''],
                'show_in_rest'      => false,
            ],
        );

        register_setting(
            'dsgo_apps_settings',
            self::OPTION_HARNESS_SHARE_CONTENT,
            [
                'type'              => 'boolean',
                'description'       => __('Allow the harness to read recent published content for context.', 'dsgo-apps'),
                'sanitize_callback' => static fn($v) => (bool) $v,
                'default'           => false,
                'show_in_rest'      => false,
            ],
        );

        add_action('admin_menu', [self::class, 'register_settings_page']);
        add_action('update_option_' . self::OPTION_URL_PREFIX, [self::class, 'on_prefix_changed'], 10, 2);
    }

    /**
     * Sanitize the per-tier model assignments array. Empty string per tier is
     * always valid — it means "use the registry default for that tier". A
     * non-empty value must look like a plausible provider model ID (loose
     * regex, since IDs differ across providers).
     *
     * @param mixed $candidate
     * @return array{routing:string,main:string}
     */
    public static function sanitize_harness_models(mixed $candidate): array {
        $out = ['routing' => '', 'main' => ''];
        if (!is_array($candidate)) {
            return $out;
        }
        foreach (Harness_Models::TIERS as $tier) {
            $val = isset($candidate[$tier]) && is_string($candidate[$tier]) ? trim($candidate[$tier]) : '';
            if ($val === '') {
                continue;
            }
            // Accept anything matching the loose model-id shape used across
            // providers (lowercase letters/digits, hyphens, periods, underscores,
            // up to 96 chars). Anything else gets dropped silently — the option
            // still persists, just with the offending tier set to empty (default).
            if (preg_match('/^[a-z0-9._-]{1,96}$/i', $val)) {
                $out[$tier] = $val;
            }
        }
        return $out;
    }

    public static function get_url_prefix(): string {
        $value = get_option(self::OPTION_URL_PREFIX, self::DEFAULT_URL_PREFIX);
        if (!is_string($value) || !self::is_valid_url_prefix($value)) {
            return self::DEFAULT_URL_PREFIX;
        }
        return $value;
    }

    public static function is_valid_url_prefix(string $candidate): bool {
        if ($candidate === '') return false;
        if (strlen($candidate) > 31) return false;
        if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/', $candidate)) return false;
        if (in_array($candidate, self::RESERVED_PREFIXES, true)) return false;
        return true;
    }

    public static function sanitize_url_prefix(mixed $candidate): string {
        if (!is_string($candidate)) {
            add_settings_error(
                self::OPTION_URL_PREFIX,
                'invalid_type',
                __('URL prefix must be a string.', 'dsgo-apps'),
            );
            return self::get_url_prefix();
        }
        $candidate = strtolower(trim($candidate, " \t\n\r\0\x0B/"));
        if (!self::is_valid_url_prefix($candidate)) {
            add_settings_error(
                self::OPTION_URL_PREFIX,
                'invalid_prefix',
                sprintf(
                    /* translators: %s: prefix the user submitted */
                    __('"%s" is not a valid URL prefix. Use 1–31 lowercase letters, digits, or hyphens, starting with a letter, and avoid reserved WordPress paths.', 'dsgo-apps'),
                    $candidate === '' ? '(empty)' : $candidate,
                ),
            );
            return self::get_url_prefix();
        }
        return $candidate;
    }

    /**
     * Cached lookup of the slug of the single mount=root app, or null if none.
     */
    public static function get_root_app_id(): ?string {
        $value = get_option(self::OPTION_ROOT_APP_ID, '');
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function set_root_app_id(?string $app_id): void {
        if ($app_id === null || $app_id === '') {
            delete_option(self::OPTION_ROOT_APP_ID);
            return;
        }
        update_option(self::OPTION_ROOT_APP_ID, $app_id, false);
    }

    /**
     * Rebuild the cached root-app lookup from manifests in the database.
     * Called on install/uninstall paths — never trusts the option without
     * verifying a published post still backs it.
     *
     * Uses a single targeted DB query against the dedicated
     * `dsgo_apps_mount_mode` meta key the installer maintains, so this
     * stays O(1) regardless of how many apps are installed.
     */
    public static function refresh_root_app_id(): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.post_name FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_key = 'dsgo_apps_mount_mode'
               AND pm.meta_value = 'root'
             LIMIT 1",
            PostType::SLUG,
        ));
        if ($row && is_string($row->post_name) && $row->post_name !== '') {
            self::set_root_app_id($row->post_name);
            return;
        }
        self::set_root_app_id(null);
    }

    public static function on_prefix_changed(mixed $old, mixed $new): void {
        if ($old === $new) return;
        // Permalink structure didn't change but our rules did — flush.
        flush_rewrite_rules(false);
    }

    public const SETTINGS_PAGE_SLUG = 'dsgo-apps-settings';

    public static function register_settings_page(): void {
        // Mount under the top-level "DSGo Apps" menu rather than the global
        // Settings menu — keeps the plugin's surfaces grouped and matches
        // how other multi-page plugins organize their admin.
        add_submenu_page(
            AdminPage::MENU_SLUG,
            __('Settings', 'dsgo-apps'),
            __('Settings', 'dsgo-apps'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [self::class, 'render_settings_page'],
        );
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'dsgo-apps_page_' . self::SETTINGS_PAGE_SLUG) {
            return;
        }
        $css      = plugins_url('assets/admin/admin-page.css', DSGO_APPS_FILE);
        $css_path = DSGO_APPS_PATH . 'assets/admin/admin-page.css';
        $ver      = file_exists($css_path) ? (string) (int) filemtime($css_path) : '0';
        wp_enqueue_style('dsgo-admin-page', $css, [], $ver);
    }

    /**
     * Enumerate all text-generation models exposed by configured Connectors,
     * grouped by provider id. Used by the Settings page to populate per-tier
     * <select> elements without depending on JS / REST. Returns [] silently
     * when WP AI Client is unavailable.
     *
     * @return array<string,list<array{id:string,label:string}>>
     */
    private static function list_available_models_for_settings(): array {
        if (!function_exists('wp_supports_ai') || !\wp_supports_ai()) {
            return [];
        }
        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $req = \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromArray([
                'requiredCapabilities' => ['text_generation'],
                'requiredOptions'      => [],
            ]);
            $grouped = [];
            foreach ($registry->getRegisteredProviderIds() as $pid) {
                $pid = strtolower((string) $pid);
                if (!$registry->isProviderConfigured($pid)) {
                    continue;
                }
                $models = $registry->findProviderModelsMetadataForSupport($pid, $req);
                $entries = [];
                foreach ($models as $meta) {
                    $id = (string) $meta->getId();
                    $label = method_exists($meta, 'getName') ? ((string) ($meta->getName() ?: $id)) : $id;
                    $entries[] = ['id' => $id, 'label' => $label];
                }
                if (!empty($entries)) {
                    $grouped[$pid] = $entries;
                }
            }
            return $grouped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $prefix      = self::get_url_prefix();
        $root_slug   = self::get_root_app_id();
        $apps_url    = admin_url('admin.php?page=' . AdminPage::MENU_SLUG);
        $share_content = (bool) get_option(self::OPTION_HARNESS_SHARE_CONTENT, false);

        // Harness model assignments. Build the list of available models once,
        // grouped by provider, then render two <select>s — one per tier.
        $tier_assignments = Harness_Models::get_tier_assignments();
        $available_models = self::list_available_models_for_settings();
        $tier_defaults    = [
            'routing' => Harness_Models::resolve_for_tier('routing', Harness_Models::detect_active_provider()),
            'main'    => Harness_Models::resolve_for_tier('main',    Harness_Models::detect_active_provider()),
        ];
        ?>
        <div class="dsgo-admin dsgo-admin--settings">
            <header class="dsgo-admin__hero">
                <p class="dsgo-admin__eyebrow"><?php esc_html_e('DesignSetGo · Settings', 'dsgo-apps'); ?></p>
                <h1 class="dsgo-admin__title">
                    <?php esc_html_e('Routing & site home.', 'dsgo-apps'); ?>
                </h1>
                <p class="dsgo-admin__lede">
                    <?php esc_html_e('Configure the URL segment your apps live under, and review which app currently owns the site root.', 'dsgo-apps'); ?>
                </p>
            </header>

            <div class="dsgo-settings__notices" role="alert" aria-live="assertive" aria-atomic="true">
                <?php settings_errors(self::OPTION_URL_PREFIX); ?>
            </div>

            <form method="post" action="options.php" class="dsgo-admin__settings-form">
                <?php settings_fields('dsgo_apps_settings'); ?>
                <?php do_settings_sections('dsgo_apps_settings'); ?>

                <section class="dsgo-card" aria-labelledby="dsgo-settings-prefix-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-settings-prefix-heading" class="dsgo-card__title"><?php esc_html_e('URL prefix', 'dsgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle">
                            <?php esc_html_e('The path segment under which prefixed-mount apps are served. Each app gets its own slug below this.', 'dsgo-apps'); ?>
                        </p>
                    </header>

                    <div class="dsgo-field">
                        <label class="dsgo-field__label" for="dsgo_apps_url_prefix">
                            <?php esc_html_e('Path segment', 'dsgo-apps'); ?>
                        </label>
                        <div class="dsgo-prefix-row">
                            <code class="dsgo-prefix-row__token">/</code>
                            <input
                                name="<?php echo esc_attr(self::OPTION_URL_PREFIX); ?>"
                                id="dsgo_apps_url_prefix"
                                type="text"
                                value="<?php echo esc_attr($prefix); ?>"
                                class="dsgo-input dsgo-prefix-row__input"
                                pattern="[a-z][a-z0-9-]{0,30}"
                                maxlength="31"
                                spellcheck="false"
                                autocapitalize="off"
                                autocorrect="off"
                            />
                            <code class="dsgo-prefix-row__token">/{app}/{path}</code>
                        </div>
                        <p class="dsgo-field__hint">
                            <?php esc_html_e('Lowercase letters, digits, hyphens. 1–31 chars. Must start with a letter. Default: "apps". Reserved WordPress paths are rejected.', 'dsgo-apps'); ?>
                        </p>
                    </div>
                </section>

                <section class="dsgo-card" aria-labelledby="dsgo-settings-home-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-settings-home-heading" class="dsgo-card__title"><?php esc_html_e('Site home', 'dsgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle">
                            <?php esc_html_e('At most one app can own the site root URL at a time. Set or change this from the Apps page.', 'dsgo-apps'); ?>
                        </p>
                    </header>

                    <?php if ($root_slug !== null): ?>
                        <div class="dsgo-settings__home dsgo-settings__home--active" aria-current="true">
                            <span class="dsgo-settings__home-badge" aria-hidden="true">⌂</span>
                            <div class="dsgo-settings__home-body">
                                <p class="dsgo-settings__home-eyebrow"><?php esc_html_e('Currently serving', 'dsgo-apps'); ?></p>
                                <p class="dsgo-settings__home-slug"><code><?php echo esc_html($root_slug); ?></code></p>
                                <p class="dsgo-settings__home-explainer">
                                    <?php esc_html_e('This app is mounted at the site root. Inline-mode root apps fill in any path WordPress would 404 on; iframe-mode root apps render at "/" only. Real WP pages, posts, and feeds always win.', 'dsgo-apps'); ?>
                                </p>
                                <p class="dsgo-settings__home-link">
                                    <a href="<?php echo esc_url($apps_url); ?>"><?php esc_html_e('Manage on the Apps page →', 'dsgo-apps'); ?></a>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="dsgo-settings__home dsgo-settings__home--empty">
                            <p class="dsgo-settings__home-empty">
                                <?php esc_html_e('No app is set as site home. All installed apps live under the URL prefix above.', 'dsgo-apps'); ?>
                            </p>
                            <p class="dsgo-settings__home-link">
                                <a href="<?php echo esc_url($apps_url); ?>"><?php esc_html_e('Pick one on the Apps page →', 'dsgo-apps'); ?></a>
                            </p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="dsgo-card" aria-labelledby="dsgo-settings-harness-models-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-settings-harness-models-heading" class="dsgo-card__title"><?php esc_html_e('Harness model assignments', 'dsgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle">
                            <?php esc_html_e('Pick the AI model used for each role in the build loop. Leaving a tier on "Use default" lets DesignSetGo pick the most appropriate model from your configured Connectors.', 'dsgo-apps'); ?>
                        </p>
                    </header>

                    <?php if (empty($available_models)): ?>
                        <p class="dsgo-field__hint">
                            <?php
                            printf(
                                /* translators: %s: link to the WordPress Connectors settings */
                                esc_html__('No AI Connectors are configured yet. %s before assigning models.', 'dsgo-apps'),
                                '<a href="' . esc_url(admin_url('options-connectors.php')) . '">' . esc_html__('Set one up under Settings → Connectors', 'dsgo-apps') . '</a>',
                            );
                            ?>
                        </p>
                    <?php else: ?>
                        <?php
                        $tier_meta = [
                            'routing' => [
                                'label' => __('Routing', 'dsgo-apps'),
                                'hint'  => __('Used on iteration 1 for intent classification and skill picking. Pick something cheap (Haiku, GPT mini, Gemini Flash). Falls back to the cheapest available model when set to "Use default".', 'dsgo-apps'),
                            ],
                            'main' => [
                                'label' => __('Main', 'dsgo-apps'),
                                'hint'  => __('Used from iteration 2 onward to write code, validate, and finish the bundle. Pick your strongest model (Sonnet, GPT-5, Gemini Pro).', 'dsgo-apps'),
                            ],
                        ];
                        foreach (Harness_Models::TIERS as $tier):
                            $assigned = $tier_assignments[$tier];
                            $resolved = $tier_defaults[$tier];
                            $field_id = 'dsgo_apps_harness_models_' . $tier;
                        ?>
                            <div class="dsgo-field">
                                <label class="dsgo-field__label" for="<?php echo esc_attr($field_id); ?>">
                                    <?php echo esc_html($tier_meta[$tier]['label']); ?>
                                </label>
                                <select
                                    name="<?php echo esc_attr(Harness_Models::OPTION_TIER_MODELS . '[' . $tier . ']'); ?>"
                                    id="<?php echo esc_attr($field_id); ?>"
                                    class="dsgo-input"
                                >
                                    <option value="" <?php selected($assigned, ''); ?>>
                                        <?php
                                        if ($resolved !== '') {
                                            /* translators: %s: model id resolved from the registry */
                                            printf(esc_html__('Use default (%s)', 'dsgo-apps'), esc_html($resolved));
                                        } else {
                                            esc_html_e('Use default', 'dsgo-apps');
                                        }
                                        ?>
                                    </option>
                                    <?php foreach ($available_models as $pid => $entries): ?>
                                        <optgroup label="<?php echo esc_attr(ucfirst($pid)); ?>">
                                            <?php foreach ($entries as $entry): ?>
                                                <option value="<?php echo esc_attr($entry['id']); ?>" <?php selected($assigned, $entry['id']); ?>>
                                                    <?php echo esc_html($entry['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <p class="dsgo-field__hint">
                                    <?php echo esc_html($tier_meta[$tier]['hint']); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="dsgo-card" aria-labelledby="dsgo-settings-harness-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-settings-harness-heading" class="dsgo-card__title"><?php esc_html_e('Harness data sharing', 'dsgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle">
                            <?php esc_html_e('Controls what context the in-admin app builder may read from this site when generating apps.', 'dsgo-apps'); ?>
                        </p>
                    </header>

                    <div class="dsgo-field dsgo-field--toggle">
                        <label class="dsgo-toggle">
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_HARNESS_SHARE_CONTENT); ?>"
                                value="1"
                                <?php checked($share_content, true); ?>
                            />
                            <span class="dsgo-toggle__label">
                                <strong><?php esc_html_e('Share recent content with the harness', 'dsgo-apps'); ?></strong>
                                <span class="dsgo-field__hint">
                                    <?php esc_html_e('When enabled, the harness can read up to 5 recent published post titles and excerpts to match the site\'s tone and content shape. Posts the visitor cannot read are never included. Off by default — site content stays out of the model\'s context until you opt in.', 'dsgo-apps'); ?>
                                </span>
                            </span>
                        </label>
                    </div>
                </section>

                <div class="dsgo-actions dsgo-actions--settings">
                    <button type="submit" class="button button-primary button-hero" name="submit">
                        <?php esc_html_e('Save changes', 'dsgo-apps'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
