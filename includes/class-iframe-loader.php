<?php
/**
 * Iframe loader — renders the host page that embeds the sandboxed app iframe.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class IframeLoader {

    public static function maybe_render(): void {
        $app_id = get_query_var(Rewrite::QUERY_VAR);
        if ($app_id === '' || $app_id === null) {
            return;
        }
        self::render((string) $app_id);
        exit;
    }

    /**
     * Root-mount dispatcher for iframe-mode apps. Only fires for the exact
     * site root URL; any other path falls through. Iframe-mode home apps
     * cannot catch 404s — the consent copy makes that explicit at promote.
     *
     * Uses REQUEST_URI rather than `is_front_page()` because the latter is
     * driven by the main WP query and returns false whenever WP can't
     * resolve the home (empty site with no posts, missing static front
     * page object, query error). Root-mount must work on a brand-new site
     * before any WP content exists, so we key off the raw request path.
     */
    public static function maybe_dispatch_root(): void {
        $root_slug = Settings::get_root_app_id();
        if ($root_slug === null) return;
        $matched = get_query_var(Rewrite::QUERY_VAR);
        if ($matched !== '' && $matched !== null) return;
        if (self::current_request_path() !== '/') return;

        $post = get_page_by_path($root_slug, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            Settings::refresh_root_app_id();
            return;
        }
        $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($manifest)) return;
        if (($manifest['isolation'] ?? 'inline') !== 'iframe') return;

        self::render($root_slug);
        exit;
    }

    private static function current_request_path(): string {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $uri  = is_string($uri) ? $uri : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return '/';
        // Normalize "/foo/" → "/foo", but keep the bare root as "/".
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function render(string $app_id): void {
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            self::render_error_page(
                404,
                __('App not found', 'dsgo-apps'),
                sprintf(
                    /* translators: %s: app slug from the URL */
                    __('No app named "%s" is installed on this site.', 'dsgo-apps'),
                    $app_id,
                ),
            );
            return;
        }
        $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($manifest)) {
            self::render_error_page(
                500,
                __('App misconfigured', 'dsgo-apps'),
                __('This app is missing its manifest. Reinstall the bundle to repair it.', 'dsgo-apps'),
            );
            return;
        }
        if (!in_array('page', $manifest['display']['modes'] ?? [], true)) {
            self::render_error_page(
                404,
                __('App not available here', 'dsgo-apps'),
                __('This app is installed but does not support page-mode display.', 'dsgo-apps'),
            );
            return;
        }

        $context = [
            'bridgeVersion' => 1,
            'appId'         => $app_id,
            'mode'          => 'page',
            'locale'        => get_locale(),
            'theme'         => 'light',
            'blockProps'    => null,
            'routeParams'   => (object) [],
            'path'          => self::iframe_app_relative_path($app_id),
            'search'        => self::iframe_search_string(),
            'hash'          => '',
            'mountPrefix'   => Settings::get_root_app_id() === $app_id
                ? ''
                : Settings::app_base_path($app_id),
        ];
        if (in_array('ai', $manifest['permissions']['read'] ?? [], true)) {
            $context['aiTimeoutSeconds'] = (int) ($manifest['ai']['timeout_seconds'] ?? 60);
        }
        $nonce             = wp_create_nonce('wp_rest');
        $app_nonce         = wp_create_nonce(RestApi::app_nonce_action(get_current_user_id(), $app_id));
        $manifest_pub      = self::manifest_public_view($manifest);
        $perm_map          = Permissions::to_array();
        $iframe_src        = Bundle::url_for($app_id) . ($manifest['entry'] ?? 'index.html');
        $wp_hooks_url      = includes_url('js/dist/hooks.min.js');
        $wp_i18n_url       = includes_url('js/dist/i18n.min.js');
        $wp_url_url        = includes_url('js/dist/url.min.js');
        $api_fetch_url     = includes_url('js/dist/api-fetch.min.js');
        $rest_root         = esc_url_raw(rest_url());
        $parent_bridge_url = plugins_url('assets/parent-bridge.js', DSGO_APPS_FILE);

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
        }

        require DSGO_APPS_PATH . 'templates/iframe-loader.php';
    }

    /** @var array<string, array> */
    private static array $manifest_public_view_cache = [];

    public static function manifest_public_view(array $manifest): array {
        // Memoize per-request keyed on (id, version) so the same manifest
        // hit by both the bridge bootstrap and the iframe-loader template
        // doesn't pay twice for the array shaping.
        $key = (string) ($manifest['id'] ?? '') . '@' . (string) ($manifest['version'] ?? '');
        if (isset(self::$manifest_public_view_cache[$key])) {
            return self::$manifest_public_view_cache[$key];
        }
        return self::$manifest_public_view_cache[$key] = [
            'id'          => $manifest['id'] ?? '',
            'name'        => $manifest['name'] ?? '',
            'permissions' => $manifest['permissions'] ?? ['read' => [], 'write' => []],
            'runtime'     => ['sandbox' => $manifest['runtime']['sandbox'] ?? 'strict'],
        ];
    }

    public static function render_block_placeholder(string $reason, int $height, string $align_class): string {
        $h = max(100, min(2000, $height));
        return sprintf(
            '<div class="wp-block-dsgo-apps-embed dsgo-error %1$s" style="min-height:%2$dpx;">%3$s</div>',
            esc_attr($align_class),
            $h,
            esc_html($reason),
        );
    }

    public static function can_render_for_block(string $app_id): true|string {
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            return sprintf('App "%s" is not installed on this site.', $app_id);
        }
        $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($manifest)) {
            return sprintf('App "%s" is misconfigured (missing manifest).', $app_id);
        }
        $modes = $manifest['display']['modes'] ?? [];
        if (!is_array($modes) || !in_array('block', $modes, true)) {
            return sprintf('App "%s" does not support block embedding.', $app_id);
        }
        return true;
    }

    /**
     * Render the inline HTML for one block-mode embed: an iframe pointing
     * directly at the bundle URL, a JSON config island the multi-iframe
     * parent-bridge reads, and (once per page) the wp-includes / parent-bridge
     * script tags. No nested WP request — the embed lives in the parent post's
     * DOM, so a page with three embeds costs one WP bootstrap, not four.
     */
    public static function render_block_embed(string $app_id, int $height, bool $auto_resize, string $align_class): string {
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            return self::render_block_placeholder(sprintf('App "%s" is not installed on this site.', $app_id), $height, $align_class);
        }
        $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($manifest)) {
            return self::render_block_placeholder(sprintf('App "%s" is misconfigured (missing manifest).', $app_id), $height, $align_class);
        }
        if (!in_array('block', $manifest['display']['modes'] ?? [], true)) {
            return self::render_block_placeholder(sprintf('App "%s" does not support block embedding.', $app_id), $height, $align_class);
        }

        static $counter = 0;
        $embed_id = ++$counter;

        $context = [
            'bridgeVersion' => 1,
            'appId'         => $app_id,
            'mode'          => 'block',
            'locale'        => get_locale(),
            'theme'         => 'light',
            'blockProps'    => [
                'height'     => $height,
                'autoResize' => $auto_resize,
            ],
            'routeParams'   => (object) [],
            'path'          => '/',
            'search'        => '',
            'hash'          => '',
            'mountPrefix'   => null,
        ];
        if (in_array('ai', $manifest['permissions']['read'] ?? [], true)) {
            $context['aiTimeoutSeconds'] = (int) ($manifest['ai']['timeout_seconds'] ?? 60);
        }
        $config = [
            'context'  => $context,
            'manifest' => self::manifest_public_view($manifest),
            'permMap'  => Permissions::to_array(),
            'nonce'    => wp_create_nonce('wp_rest'),
            // Per-(user, app) storage nonce — see RestApi::permit_storage.
            'appNonce' => wp_create_nonce(RestApi::app_nonce_action(get_current_user_id(), $app_id)),
        ];

        $iframe_src = Bundle::url_for($app_id) . ($manifest['entry'] ?? 'index.html');
        $title      = sprintf(
            /* translators: %s: app display name */
            __('%s — embedded application', 'dsgo-apps'),
            $post->post_title,
        );

        $iframe_html = sprintf(
            '<iframe src="%1$s" sandbox="allow-scripts" loading="lazy" '
                . 'style="width:100%%; height:%2$dpx; border:0; display:block;" '
                . 'title="%3$s" aria-label="%4$s" '
                . 'data-dsgo-embed-id="%5$d" data-dsgo-app-id="%6$s"></iframe>',
            esc_url($iframe_src),
            $height,
            esc_attr($title),
            esc_attr($post->post_title),
            $embed_id,
            esc_attr($app_id),
        );

        $config_html = sprintf(
            '<script type="application/json" data-dsgo-embed-config="%d">%s</script>',
            $embed_id,
            wp_json_encode($config),
        );

        return sprintf(
            '<div class="wp-block-dsgo-apps-embed %1$s">%2$s%3$s%4$s</div>',
            esc_attr($align_class),
            self::block_runtime_scripts_once(),
            $iframe_html,
            $config_html,
        );
    }

    /**
     * Emit the wp-api-fetch chain + parent-bridge.js exactly once per page.
     * Multiple block embeds on the same page share a single bridge instance.
     */
    private static function block_runtime_scripts_once(): string {
        static $emitted = false;
        if ($emitted) return '';
        $emitted = true;

        $rest_root  = esc_url_raw(rest_url());
        $rest_nonce = wp_create_nonce('wp_rest');
        $deps = [
            includes_url('js/dist/hooks.min.js'),
            includes_url('js/dist/i18n.min.js'),
            includes_url('js/dist/url.min.js'),
            includes_url('js/dist/api-fetch.min.js'),
        ];
        $bridge_url = plugins_url('assets/parent-bridge.js', DSGO_APPS_FILE);

        $out  = '<script>window.wpApiSettings='
              . wp_json_encode(['root' => $rest_root, 'nonce' => $rest_nonce])
              . ';</script>';
        foreach ($deps as $url) {
            $out .= '<script src="' . esc_url($url) . '"></script>';
        }
        $out .= '<script>(function(){var af=window.wp&&window.wp.apiFetch;'
              . 'if(!af||af.__dsgoConfigured)return;'
              . 'af.use(af.createRootURLMiddleware(window.wpApiSettings.root));'
              . 'af.use(af.createNonceMiddleware(window.wpApiSettings.nonce));'
              . 'af.__dsgoConfigured=true;})();</script>';
        $out .= '<script src="' . esc_url($bridge_url) . '" defer></script>';
        return $out;
    }

    /**
     * The path within the iframe app's mount, mirroring what
     * `dsgo.context.path` is documented to expose. Mirrors
     * InlineRenderer::app_relative_path() but reads mount config directly
     * because iframe-mode never passes through the inline renderer.
     */
    private static function iframe_app_relative_path(string $app_id): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uri) ? $uri : '/';
        $req = parse_url($uri, PHP_URL_PATH);
        if (!is_string($req) || $req === '') {
            return '/';
        }
        // Trim any home subdirectory (WP installed at /sub/).
        $home_path = parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($home_path) && $home_path !== '' && $home_path !== '/' && str_starts_with($req, $home_path)) {
            $req = substr($req, strlen(rtrim($home_path, '/')));
            if ($req === '') $req = '/';
        }
        // Root-mounted iframe app owns the site root.
        if (Settings::get_root_app_id() === $app_id) {
            return $req;
        }
        $prefix = Settings::app_base_path($app_id);
        if ($req === $prefix || $req === $prefix . '/') return '/';
        if (str_starts_with($req, $prefix . '/')) {
            $rel = substr($req, strlen($prefix));
            return $rel === '' ? '/' : $rel;
        }
        return '/';
    }

    private static function iframe_search_string(): string {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        return is_string($qs) && $qs !== '' ? '?' . $qs : '';
    }

    /**
     * Render a themed error page with theme header/footer when the active
     * theme provides them. Falls back to a minimal but well-styled standalone
     * document when get_header/get_footer aren't safe to call (e.g. during a
     * REST request hijacked by template_redirect).
     */
    private static function render_error_page(int $status, string $title, string $body): void {
        if (!headers_sent()) {
            nocache_headers();
            status_header($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        // Try to use the theme — gives users header/footer/nav and consistent
        // branding. wp_die() is admin-styled and out of place on the front-end.
        $title_html = '<h1 class="dsgo-error__title">' . esc_html($title) . '</h1>';
        $body_html  = '<p class="dsgo-error__body">' . esc_html($body) . '</p>';
        $home_link  = '<p class="dsgo-error__home"><a href="' . esc_url(home_url('/')) . '">'
            . esc_html__('← Return to site', 'dsgo-apps') . '</a></p>';

        $style = '<style>
            .dsgo-error{max-width:48rem;margin:6rem auto 4rem;padding:0 1.5rem;text-align:center}
            .dsgo-error__status{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;opacity:.55;margin:0 0 .75rem}
            .dsgo-error__title{font-size:clamp(1.75rem,3.5vw,2.5rem);margin:0 0 .75rem;font-weight:600;line-height:1.2}
            .dsgo-error__body{font-size:1.0625rem;line-height:1.6;opacity:.8;margin:0 0 2rem}
            .dsgo-error__home a{display:inline-block;padding:.6rem 1.1rem;border:1px solid currentColor;border-radius:999px;text-decoration:none;font-size:.9375rem}
            .dsgo-error__home a:hover{background:currentColor;color:#fff}
        </style>';

        $rendered = false;
        if (function_exists('get_header') && function_exists('get_footer') && !is_admin()) {
            ob_start();
            try {
                get_header();
                echo '<main class="dsgo-error">' . $style;
                echo '<p class="dsgo-error__status">' . esc_html((string) $status) . '</p>';
                echo $title_html . $body_html . $home_link;
                echo '</main>';
                get_footer();
                $rendered = (ob_get_length() ?: 0) > 0;
                ob_end_flush();
            } catch (\Throwable $e) {
                ob_end_clean();
                $rendered = false;
            }
        }
        if (!$rendered) {
            $lang = esc_attr(str_replace('_', '-', get_locale()));
            echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>' . esc_html($title) . '</title>'
                . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#1a1a1a;background:#fafaf7;margin:0}</style>'
                . $style
                . '</head><body><main class="dsgo-error">'
                . '<p class="dsgo-error__status">' . esc_html((string) $status) . '</p>'
                . $title_html . $body_html . $home_link
                . '</main></body></html>';
        }
    }
}
