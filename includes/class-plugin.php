<?php
/**
 * Plugin singleton — wires hooks and strictly loads every class file.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class Plugin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->load_dependencies();
        // Schema check happens here (not on a hook) because Plugin is
        // instantiated from the `plugins_loaded` callback — adding another
        // `plugins_loaded` handler from inside a `plugins_loaded` callback
        // doesn't reliably re-enter the action, leaving the table missing
        // for users who installed before the schema landed. The check
        // itself is one autoloaded option lookup; only a stale version
        // triggers dbDelta.
        Harness_Sessions::maybe_install_schema();
        $this->register_hooks();
    }

    private function load_dependencies(): void {
        $base = DSGO_APPS_PATH . 'includes/';
        require_once $base . 'class-manifest.php';
        require_once $base . 'class-abilities-bridge.php';
        require_once $base . 'class-abilities-publisher.php';
        require_once $base . 'class-admin-publisher-loader.php';
        require_once $base . 'class-ai-bridge.php';
        require_once $base . 'class-email-bridge.php';
        require_once $base . 'class-html-sanitizer.php';
        require_once $base . 'class-csp-builder.php';
        require_once $base . 'class-permissions.php';
        require_once $base . 'class-post-type.php';
        require_once $base . 'class-settings.php';
        require_once $base . 'class-rewrite.php';
        require_once $base . 'class-storage.php';
        require_once $base . 'class-bundle.php';
        require_once $base . 'class-installer.php';
        require_once $base . 'class-artifact-normalizer.php';
        require_once $base . 'class-iframe-loader.php';
        require_once $base . 'class-inline-renderer.php';
        require_once $base . 'class-rest-api.php';
        require_once $base . 'class-sitemap-provider.php';
        require_once $base . 'class-admin-page.php';
        require_once $base . 'class-harness-validator.php';
        require_once $base . 'class-harness-autofix.php';
        require_once $base . 'class-harness-skills.php';
        require_once $base . 'class-harness-tools.php';
        require_once $base . 'class-harness-prompt.php';
        require_once $base . 'class-harness-models.php';
        require_once $base . 'class-harness-provider.php';
        require_once $base . 'class-harness-critic.php';
        require_once $base . 'class-harness-generator.php';
        require_once $base . 'class-harness-stream.php';
        require_once $base . 'class-harness-run-checkpoint.php';
        require_once $base . 'class-harness-storage.php';
        require_once $base . 'class-harness-state.php';
        require_once $base . 'class-harness-sessions.php';
        require_once $base . 'class-harness-cli.php';
        require_once $base . 'class-harness-rest-controller.php';
        require_once $base . 'class-harness-admin-page.php';
    }

    private function register_hooks(): void {
        add_action('init', [PostType::class, 'register']);
        add_action('init', [Rewrite::class, 'register']);
        add_action('init', function (): void {
            // Skip block registration on contexts that never render blocks.
            // WP-Cron, REST without blocks support, and XML-RPC don't need
            // the block type loaded; trimming the block-init cost matters
            // because it parses block.json + index.asset.php on every
            // request.
            if (wp_doing_cron()) return;
            if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
            register_block_type_from_metadata(DSGO_APPS_PATH . 'block/build');
        });
        add_action('init', [Settings::class, 'register']);
        add_action('init', static function (): void {
            \register_setting('dsgo_apps', 'dsgo_apps_harness_model', [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
            ]);
            // Opt-in: lets the harness sample recent post titles/excerpts to inform
            // tone and content shape on apps that surface site content. Off by
            // default — site content stays out of the model's context unless
            // explicitly enabled by a site admin via Settings → DSGo Apps.
            // Registered under dsgo_apps_settings so the settings-page form can save it.
            \register_setting('dsgo_apps_settings', 'dsgo_apps_harness_share_content', [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => static fn($v): bool => (bool) $v,
                'show_in_rest'      => false,
            ]);
        });
        AdminPage::register();
        Harness_Admin_Page::register();
        add_action('admin_notices', [self::class, 'maybe_render_activation_notice']);
        AdminPublisherLoader::register();
        add_action('rest_api_init', [RestApi::class, 'register']);
        add_action('rest_api_init', static function (): void {
            (new Harness_REST_Controller())->register_routes();
        });
        add_action(RestApi::USER_STORAGE_CLEANUP_HOOK, [RestApi::class, 'cleanup_user_storage_batch'], 10, 1);
        add_action('dsgo_apps_prune_harness_drafts', [Harness_Storage::class, 'prune_expired']);
        add_action('template_redirect', [InlineRenderer::class, 'maybe_dispatch'], 5);
        add_action('template_redirect', [InlineRenderer::class, 'maybe_dispatch_root'], 7);
        add_action('template_redirect', [IframeLoader::class, 'maybe_dispatch_root'], 8);
        add_action('template_redirect', [IframeLoader::class, 'maybe_render'], 10);
        add_action('wp_sitemaps_init', function (\WP_Sitemaps $sitemaps): void {
            // Slug must be all-lowercase letters; see SitemapProvider::$name.
            $sitemaps->registry->add_provider('dsgoapps', new SitemapProvider());
        });
    }

    public static function activate(): void {
        // Ensure all dependencies are loaded — clean-process activation
        // (e.g. WP-CLI) may invoke this before plugins_loaded fires.
        self::get_instance();

        PostType::register();
        Rewrite::register();
        flush_rewrite_rules(false);

        // Riff sessions table — created on activate so it exists immediately.
        // Also re-checked on plugins_loaded for users who never deactivate
        // (e.g. via composer update) so a schema bump auto-applies.
        Harness_Sessions::install_schema();
        update_option(Harness_Sessions::DB_VERSION_OPT, Harness_Sessions::DB_VERSION, false);

        $upload_dir = wp_upload_dir();
        $apps_dir   = trailingslashit($upload_dir['basedir']) . 'dsgo-apps';
        if (!is_dir($apps_dir)) {
            wp_mkdir_p($apps_dir);
            file_put_contents($apps_dir . '/index.html', '<!-- silence is golden -->');
        }

        // Schedule daily draft-pruning cron (offset by 1 hour so first run
        // doesn't fire immediately on activation).
        if (!wp_next_scheduled('dsgo_apps_prune_harness_drafts')) {
            wp_schedule_event(time() + 3600, 'daily', 'dsgo_apps_prune_harness_drafts');
        }

        // One-shot welcome notice so admins land on the install screen instead
        // of an empty Plugins list. Cleared after first render.
        set_transient('dsgo_apps_activation_notice', '1', 60);
    }

    public static function maybe_render_activation_notice(): void {
        if (!current_user_can('manage_options')) return;
        if (get_transient('dsgo_apps_activation_notice') !== '1') return;
        delete_transient('dsgo_apps_activation_notice');
        $url = admin_url('admin.php?page=' . AdminPage::MENU_SLUG);
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php esc_html_e('DesignSetGo Apps is ready.', 'dsgo-apps'); ?></strong>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: 1: URL to install page */
                        __('Install your first app at <a href="%1$s">DSGo Apps</a>, or run <code>npx designsetgo apps deploy</code> from a project directory.', 'dsgo-apps'),
                        esc_url($url),
                    ),
                    ['a' => ['href' => true], 'code' => []],
                );
                ?>
            </p>
        </div>
        <?php
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('dsgo_apps_prune_harness_drafts');
        flush_rewrite_rules(false);
    }
}
