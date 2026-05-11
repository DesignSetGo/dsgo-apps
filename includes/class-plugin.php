<?php
/**
 * Plugin singleton — wires hooks and strictly loads every class file.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Plugin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies(): void {
        $base = DSGO_APPS_PATH . 'includes/';
        require_once $base . 'class-manifest.php';
        require_once $base . 'class-abilities-bridge.php';
        require_once $base . 'class-abilities-publisher.php';
        require_once $base . 'class-commerce-bridge.php';
        require_once $base . 'class-admin-publisher-loader.php';
        require_once $base . 'class-ai-bridge.php';
        require_once $base . 'class-email-bridge.php';
        require_once $base . 'class-media-bridge.php';
        require_once $base . 'class-html-sanitizer.php';
        require_once $base . 'class-csp-builder.php';
        require_once $base . 'class-permissions.php';
        require_once $base . 'class-bucket-renderer.php';
        require_once $base . 'class-bridge-method-registry.php';
        require_once $base . 'class-help-bridge.php';
        require_once $base . 'class-secret-vault.php';
        require_once $base . 'class-http-proxy-log.php';
        require_once $base . 'class-http-proxy-bridge.php';
        require_once $base . 'class-privacy.php';
        require_once $base . 'class-post-type.php';
        require_once $base . 'class-settings.php';
        require_once $base . 'class-rewrite.php';
        require_once $base . 'class-storage.php';
        require_once $base . 'class-bundle.php';
        require_once $base . 'class-block-styles.php';
        require_once $base . 'class-data-sources.php';
        require_once $base . 'class-installer.php';
        require_once $base . 'class-artifact-normalizer.php';
        require_once $base . 'class-iframe-loader.php';
        require_once $base . 'class-inline-renderer.php';
        require_once $base . 'class-rest-api.php';
        require_once $base . 'class-sitemap-provider.php';
        require_once $base . 'class-admin-page.php';
        require_once $base . 'class-pro-upsell.php';
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
        AdminPage::register();
        ProUpsell::register();
        add_action('admin_notices', [self::class, 'maybe_render_activation_notice']);
        AdminPublisherLoader::register();
        Privacy::register();
        add_action('rest_api_init', [RestApi::class, 'register']);
        add_action('rest_api_init', [BlockStyles::class, 'register']);
        // admin-ajax handlers for the Secrets tab — gated on manage_options
        // + per-app nonce inside each callback. Registered on init so they
        // resolve regardless of whether REST is being served on this request.
        add_action('init', [RestApi::class, 'register_admin_ajax']);
        add_action(RestApi::USER_STORAGE_CLEANUP_HOOK, [RestApi::class, 'cleanup_user_storage_batch'], 10, 1);
        // Daily retention purge for the HTTP proxy audit log. Scheduled
        // in activate(); the hook stays registered here so the cron
        // dispatcher can resolve the callback on any boot of the site.
        add_action(Http_Proxy_Log::CRON_HOOK, [Http_Proxy_Log::class, 'purge_expired']);
        add_action('template_redirect', [InlineRenderer::class, 'maybe_dispatch'], 5);
        add_action('template_redirect', [InlineRenderer::class, 'maybe_dispatch_root'], 7);
        add_action('template_redirect', [IframeLoader::class, 'maybe_dispatch_root'], 8);
        add_action('template_redirect', [IframeLoader::class, 'maybe_render'], 10);
        add_action('wp_sitemaps_init', function (\WP_Sitemaps $sitemaps): void {
            // Slug must be all-lowercase letters; see SitemapProvider::$name.
            $sitemaps->registry->add_provider('dsgoapps', new SitemapProvider());
        });
        // When a post is saved or deleted, dynamic-route renders that
        // resolved against a live `wp:posts` / `wp:pages` / `wp:cpt:<slug>`
        // source can be stale. Bump the per-app render-cache version on
        // every install so the next request re-resolves the entry. Cheap:
        // it just rotates a UUID in a single option, and only fires on
        // post-status transitions and explicit deletes.
        add_action('save_post', [self::class, 'invalidate_dynamic_route_cache_for_post'], 10, 2);
        add_action('deleted_post', [self::class, 'invalidate_dynamic_route_cache_for_post'], 10, 2);

        // WooCommerce mutations that don't always trip save_post (stock-only
        // updates, variation saves) but must still invalidate apps backed by
        // `wc:products`. `woocommerce_update_product` covers most edits that
        // change shape; the stock_status hooks handle inventory transitions
        // that bypass the post-save path.
        add_action('woocommerce_update_product',             [self::class, 'invalidate_dynamic_route_cache_for_wc_product'], 10, 1);
        add_action('woocommerce_delete_product',             [self::class, 'invalidate_dynamic_route_cache_for_wc_product'], 10, 1);
        add_action('woocommerce_product_set_stock_status',   [self::class, 'invalidate_dynamic_route_cache_for_wc_product'], 10, 1);
        add_action('woocommerce_variation_set_stock_status', [self::class, 'invalidate_dynamic_route_cache_for_wc_product'], 10, 1);
    }

    /**
     * Bump the render-cache version on every installed DSGo app whose
     * manifest declares at least one route backed by a `wp:*` live source
     * for this post's post type. Limits the work to apps that actually
     * care about this content, instead of clobbering every app's cache.
     */
    public static function invalidate_dynamic_route_cache_for_post(int $post_id, $post = null): void {
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }
        // Skip post revisions and autosaves — they aren't reachable through
        // a live data source (revisions are post_type=revision; autosaves
        // are post_status=auto-draft).
        if (wp_is_post_revision($post)) return;
        if ($post->post_status === 'auto-draft' || $post->post_status === 'inherit') return;

        $type = $post->post_type;
        $matching_sources = [];
        if ($type === 'post') $matching_sources[] = 'wp:posts';
        if ($type === 'page') $matching_sources[] = 'wp:pages';
        $matching_sources[] = 'wp:cpt:' . $type;
        if ($type === 'product') $matching_sources[] = 'wc:products';

        self::bump_apps_with_route_source($matching_sources);
    }

    /**
     * Bump the render-cache version on every installed DSGo app whose
     * manifest declares a `wc:products` route. Invoked from WooCommerce
     * product/variation hooks that don't always fire save_post.
     */
    public static function invalidate_dynamic_route_cache_for_wc_product(int $product_id): void {
        if ($product_id <= 0) return;
        self::bump_apps_with_route_source(['wc:products']);
    }

    /**
     * @param string[] $matching_sources
     */
    private static function bump_apps_with_route_source(array $matching_sources): void {
        if ($matching_sources === []) return;

        $apps = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        foreach ($apps as $app_post_id) {
            $manifest = get_post_meta((int) $app_post_id, 'dsgo_apps_manifest', true);
            if (!is_array($manifest) || empty($manifest['routes'])) continue;
            foreach ($manifest['routes'] as $route) {
                $src = $route['dataset']['source'] ?? null;
                if (is_string($src) && in_array($src, $matching_sources, true)) {
                    $app_id = $manifest['id'] ?? null;
                    if (is_string($app_id) && $app_id !== '') {
                        InlineRenderer::bump_cache_version($app_id);
                    }
                    break;
                }
            }
        }
    }

    public static function activate(): void {
        // Ensure all dependencies are loaded — clean-process activation
        // (e.g. WP-CLI) may invoke this before plugins_loaded fires.
        self::get_instance();

        PostType::register();
        Rewrite::register();
        flush_rewrite_rules(false);

        $upload_dir = wp_upload_dir();
        $apps_dir   = trailingslashit($upload_dir['basedir']) . 'dsgo-apps';
        if (!is_dir($apps_dir)) {
            wp_mkdir_p($apps_dir);
            file_put_contents($apps_dir . '/index.html', '<!-- silence is golden -->');
        }

        // HTTP proxy audit log table + daily retention cron. dbDelta is
        // idempotent, so re-running activation is safe; wp_schedule_event
        // no-ops if the event is already queued.
        Http_Proxy_Log::create_table();
        if (!wp_next_scheduled(Http_Proxy_Log::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Http_Proxy_Log::CRON_HOOK);
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
        // Clear any pending batched usermeta cleanup jobs so they don't sit in
        // wp_cron pointing at a hook that no longer has a listener.
        // wp_unschedule_hook clears events regardless of args; wp_clear_scheduled_hook
        // would only match events scheduled with no args.
        wp_unschedule_hook(RestApi::USER_STORAGE_CLEANUP_HOOK);
        wp_unschedule_hook(Http_Proxy_Log::CRON_HOOK);
        flush_rewrite_rules(false);
    }
}
