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

    /**
     * Daily cron hook for log + queue retention sweeps. Owned by this
     * class; both CronLog and WebhookLog hang their prune calls off it
     * via run_daily_cleanup() so the scheduled event count stays at one
     * regardless of how many tables join the sweep.
     */
    public const DAILY_CLEANUP_HOOK = 'dsgo_apps_daily_cleanup';

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
        require_once $base . 'class-dsgo-abilities.php';
        require_once $base . 'class-commerce-bridge.php';
        require_once $base . 'class-admin-publisher-loader.php';
        require_once $base . 'class-ai-bridge.php';
        require_once $base . 'class-email-bridge.php';
        require_once $base . 'class-media-bridge.php';
        require_once $base . 'class-media-publisher.php';
        require_once $base . 'class-html-sanitizer.php';
        require_once $base . 'class-csp-builder.php';
        require_once $base . 'class-pro-feature-gate.php';
        require_once $base . 'class-permissions.php';
        require_once $base . 'class-bucket-renderer.php';
        require_once $base . 'class-bridge-method-registry.php';
        require_once $base . 'class-help-bridge.php';
        require_once $base . 'class-secret-vault.php';
        require_once $base . 'class-http-proxy-log.php';
        require_once $base . 'class-http-proxy-bridge.php';
        require_once $base . 'class-cron-scheduler.php';
        require_once $base . 'class-cron-log.php';
        require_once $base . 'class-cron-dispatcher.php';
        require_once $base . 'class-webhook-auth.php';
        require_once $base . 'class-webhook-idempotency.php';
        require_once $base . 'class-webhook-rate-limiter.php';
        require_once $base . 'class-webhook-log.php';
        require_once $base . 'class-webhook-queue.php';
        require_once $base . 'class-async-webhook-handler.php';
        require_once $base . 'class-webhook-handler.php';
        require_once $base . 'class-webhook-router.php';
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
        require_once $base . 'class-shortcode.php';
        require_once $base . 'class-elementor-widget.php';
        require_once $base . 'class-sitemap-provider.php';
        require_once $base . 'class-admin-page.php';
        require_once $base . 'class-ai-context-pack.php';
        require_once $base . 'class-llms-txt-integration.php';
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
        add_action('init', [Shortcode::class, 'register']);
        // Elementor widget. The registration hooks onto Elementor's own
        // `elementor/widgets/register` action, which only fires when
        // Elementor is active — safe to call unconditionally here.
        ElementorWidget::register();
        AdminPage::register();
        LlmsTxtIntegration::register();
        add_action('admin_notices', [self::class, 'maybe_render_activation_notice']);
        // Deferred to init@9 so Pro's plugins_loaded@20 filter is already
        // registered before the gate check inside register() runs.
        add_action('init', [AdminPublisherLoader::class, 'register'], 9);
        Privacy::register();
        add_action('rest_api_init', [RestApi::class, 'register']);
        // MCP Adapter publishes plugin-scoped abilities registered here.
        add_action('wp_abilities_api_init', [DSGoAbilities::class, 'register']);
        add_action('rest_api_init', [BlockStyles::class, 'register']);
        // Webhook endpoint routes — Pro-gated inside the router. One
        // POST route per manifest-declared webhooks.endpoints[] entry.
        // Each route delegates to WebhookHandler::handle.
        add_action('rest_api_init', [WebhookRouter::class, 'register_all']);
        // admin-ajax handlers for the Secrets tab — gated on manage_options
        // + per-app nonce inside each callback. Registered on init so they
        // resolve regardless of whether REST is being served on this request.
        add_action('init', [RestApi::class, 'register_admin_ajax']);
        add_action(RestApi::USER_STORAGE_CLEANUP_HOOK, [RestApi::class, 'cleanup_user_storage_batch'], 10, 1);
        // Daily retention purge for the HTTP proxy audit log. Scheduled
        // in activate(); the hook stays registered here so the cron
        // dispatcher can resolve the callback on any boot of the site.
        add_action(Http_Proxy_Log::CRON_HOOK, [Http_Proxy_Log::class, 'purge_expired']);
        // Daily retention sweep for the cron + webhook audit tables
        // (Task 17 of the cron+webhooks plan). Bind here so the callback
        // resolves on every boot; the scheduled event itself is queued
        // in Plugin::activate(). Each table reads its own
        // dsgo_apps_*_log_retention_days filter so operators can tune
        // the windows independently.
        add_action(self::DAILY_CLEANUP_HOOK, [self::class, 'run_daily_cleanup']);
        // DSGo-specific cron intervals (dsgo-5min, dsgo-15min) declared in
        // app manifests. Registered at default filter priority so they're
        // visible to any wp_schedule_event() call regardless of when in
        // the boot sequence it fires.
        add_filter('cron_schedules', [CronScheduler::class, 'register_custom_schedules']);
        // Bind every installed app's scheduled jobs to CronDispatcher::run.
        // Runs on init priority 9 so the hook callbacks resolve before
        // WP-cron starts firing (cron events fire on `init` itself; the
        // hook table is checked on shutdown). One bound action per job;
        // when WP-cron fires `dsgo_apps_cron_<app>_<job>`, the dispatcher
        // looks up the ability and logs the outcome.
        add_action('init', [self::class, 'register_cron_dispatch_hooks'], 9);
        // Async webhook dispatch — WebhookHandler enqueues rows + schedules
        // a single-event hook against this action with the row id; the
        // handler decrypts, invokes the ability, and either deletes the
        // row (success) or reschedules / marks failed.
        add_action(AsyncWebhookHandler::ASYNC_HOOK, [AsyncWebhookHandler::class, 'run'], 10, 1);
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

    /**
     * Bind every installed app's scheduled jobs to CronDispatcher::run.
     *
     * Gated on `wp_doing_cron() || is_admin()` because the hook bindings
     * only matter (a) when wp-cron fires the registered hooks, or
     * (b) when the admin "Run now" button dispatches via admin-ajax.
     * Skipping the scan on front-end page loads avoids per-request
     * metaqueries for every installed app on every visitor request.
     *
     * Memoized within a single request via wp_cache_set so the scan
     * runs at most once per worker even when the hook fires multiple
     * times (e.g. admin-ajax + nested init).
     *
     * TODO(Task 7+): replace the per-request scan with a transient
     * primed by Installer::install / RestApi::delete_app, so cron
     * boot doesn't pay the post+meta cost at all in the steady state.
     * The transient should be keyed by (app_id, job_id, ability) and
     * invalidated on install / update / delete.
     */
    public static function register_cron_dispatch_hooks(): void {
        // CronDispatcher::run has no license check of its own; this gate
        // is the only enforcement point. Removing it would let cron events
        // bind and fire on unlicensed sites. The closed-gate branch also
        // sweeps any orphaned cron events left behind by a previously-active
        // license so WP-Cron stops firing into the void each interval.
        if (!ProFeatureGate::is_enabled('cron')) {
            self::sweep_orphaned_cron_events();
            return;
        }
        if (!wp_doing_cron() && !is_admin()) {
            return;
        }
        if (wp_cache_get('dispatch_hooks_bound', 'dsgo_apps_cron') === true) {
            return;
        }
        wp_cache_set('dispatch_hooks_bound', true, 'dsgo_apps_cron');

        $app_ids = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        foreach ($app_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) continue;
            $manifest_arr = get_post_meta($post_id, 'dsgo_apps_manifest', true);
            if (!is_array($manifest_arr)) continue;
            $jobs = $manifest_arr['scheduled']['jobs'] ?? null;
            if (!is_array($jobs)) continue;
            $app_id = $post->post_name;
            foreach ($jobs as $job) {
                if (!is_array($job) || !isset($job['id'], $job['ability'])
                    || !is_string($job['id']) || !is_string($job['ability'])
                ) {
                    continue;
                }
                $hook = CronScheduler::hook($app_id, $job['id']);
                $ability_name = $job['ability'];
                $job_id = $job['id'];
                add_action(
                    $hook,
                    static function () use ($app_id, $job_id, $ability_name): void {
                        CronDispatcher::run($app_id, $job_id, $ability_name);
                    },
                );
            }
        }
    }

    /**
     * Daily retention sweep for the cron + webhook audit tables.
     * Bound to `dsgo_apps_daily_cleanup` and fired once per day by
     * WP-cron. Each table's retention window is filterable
     * independently (dsgo_apps_cron_log_retention_days,
     * dsgo_apps_webhook_log_retention_days; both default to 14 days
     * via the *Log::retention_days() helpers).
     *
     * Never throws — log-prune failure must not break the cron tick.
     */
    public static function run_daily_cleanup(): void {
        try {
            CronLog::prune(CronLog::retention_days());
        } catch (\Throwable $e) {
            error_log('dsgo_apps: CronLog::prune failed: ' . $e->getMessage());
        }
        try {
            WebhookLog::prune(WebhookLog::retention_days());
        } catch (\Throwable $e) {
            error_log('dsgo_apps: WebhookLog::prune failed: ' . $e->getMessage());
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
        $apps_dir   = trailingslashit($upload_dir['basedir']) . 'designsetgo-apps';
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

        // Cron audit log table (Task 5 of the cron+webhooks plan).
        // Pruned daily via run_daily_cleanup() — see DAILY_CLEANUP_HOOK
        // scheduling below.
        CronLog::create_table();
        // Webhook audit log + async queue tables (Task 10 of the
        // cron+webhooks plan). dbDelta is idempotent so re-running
        // activation (e.g. after wp-env destroy/start) is safe.
        WebhookLog::create_table();
        WebhookQueue::create_table();

        // Daily retention sweep for the cron + webhook audit tables
        // (Task 17). One scheduled event hangs every dsgo log table's
        // prune call via run_daily_cleanup(). wp_schedule_event no-ops
        // when the event is already queued.
        if (!wp_next_scheduled(self::DAILY_CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_CLEANUP_HOOK);
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
                <strong><?php esc_html_e('DesignSetGo Apps is ready.', 'designsetgo-apps'); ?></strong>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: 1: URL to install page */
                        __('Install your first app at <a href="%1$s">DSGo Apps</a>, or run <code>npx designsetgo apps deploy</code> from a project directory.', 'designsetgo-apps'),
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
        wp_unschedule_hook(self::DAILY_CLEANUP_HOOK);
        // Deactivation is rare and intentional — bypass the transient throttle
        // so every app-job cron event is actually cleared on this run.
        self::sweep_orphaned_cron_events(true);
        flush_rewrite_rules(false);
    }

    /**
     * Remove every `dsgo_apps_cron_<app>_<job>` event that remains in the
     * WP-Cron registry. Called when the Pro gate closes (license expiry /
     * downgrade) and on plugin deactivation.
     *
     * @param bool $force When true, skip the 1-hour transient throttle. Pass
     *                    true only from deactivate() — gate-closed boots use
     *                    the throttle to avoid per-request metaqueries.
     */
    private static function sweep_orphaned_cron_events(bool $force = false): void {
        if (!$force) {
            if (get_transient('dsgo_apps_cron_sweep_done') === '1') {
                return;
            }
            set_transient('dsgo_apps_cron_sweep_done', '1', HOUR_IN_SECONDS);
        }

        self::unschedule_all_app_cron_events();
    }

    /**
     * Iterate every installed DSGo app, read its manifest, and unschedule any
     * cron events declared in `scheduled.jobs[]`. Used by both the throttled
     * gate-closed sweep and the unthrottled deactivation path.
     */
    private static function unschedule_all_app_cron_events(): void {
        $app_ids = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        foreach ($app_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $manifest_arr = get_post_meta($post_id, 'dsgo_apps_manifest', true);
            if (!is_array($manifest_arr)) {
                continue;
            }
            $jobs = $manifest_arr['scheduled']['jobs'] ?? null;
            if (!is_array($jobs)) {
                continue;
            }
            $job_ids = [];
            foreach ($jobs as $job) {
                if (is_array($job) && isset($job['id']) && is_string($job['id'])) {
                    $job_ids[] = $job['id'];
                }
            }
            if ($job_ids !== []) {
                CronScheduler::unschedule_all($post->post_name, $job_ids);
            }
        }
    }
}
