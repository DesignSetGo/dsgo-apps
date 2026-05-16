<?php
/**
 * Render pipeline for inline-mode DSGo apps.
 *
 * Coexists with IframeLoader; the plugin's request dispatcher routes by
 * manifest.isolation.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class InlineRenderer {

    /**
     * Returns [route, params] on match (params is empty for static routes),
     * null when no route matches.
     *
     * Literal matches win over `:param` matches — apps that have both
     * `/customers/list` and `/customers/:id` will serve the literal page when
     * the URL is exactly `/customers/list`.
     *
     * @return array{0:array, 1:array<string,string>}|null
     */
    public static function resolve_route(Manifest $manifest, string $path): ?array {
        $path = self::normalize_path($path);
        // Two-stage: literal first, then `:param` patterns.
        foreach ($manifest->routes as $route) {
            if (!str_contains($route['path'], ':') && $route['path'] === $path) {
                return [$route, []];
            }
        }
        foreach ($manifest->routes as $route) {
            if (!str_contains($route['path'], ':')) {
                continue;
            }
            $params = self::match_pattern_route($route['path'], $path);
            if ($params !== null) {
                return [$route, $params];
            }
        }
        return null;
    }

    /**
     * Returns true when `$path` resolves to a route on `$manifest` that declares
     * `claim: "always"`. Used by `maybe_dispatch_root` to override WP's
     * "real content wins" default for paths the app explicitly claims.
     */
    public static function route_claims_path(Manifest $manifest, string $path): bool {
        $resolved = self::resolve_route($manifest, $path);
        if ($resolved === null) {
            return false;
        }
        [$route] = $resolved;
        return ($route['claim'] ?? null) === 'always';
    }

    /**
     * Compile a `:param` path into a regex and try to match $request_path.
     * Returns the captured params on success, null on no match.
     *
     * @return array<string,string>|null
     */
    private static function match_pattern_route(string $route_path, string $request_path): ?array {
        if (!preg_match('#/:([a-z][a-z0-9_]*)(?=/|$)#', $route_path, $name_match)) {
            return null;
        }
        $param_name = $name_match[1];
        $regex_path = preg_replace(
            '#/:[a-z][a-z0-9_]*(?=/|$)#',
            '/(?P<' . $param_name . '>[^/]+)',
            $route_path,
            1,
        );
        $regex = '#^' . $regex_path . '$#';
        if (!preg_match($regex, $request_path, $m)) {
            return null;
        }
        $value = $m[$param_name] ?? '';
        if ($value === '') {
            return null;
        }
        $decoded = rawurldecode($value);
        return [$param_name => $decoded];
    }

    /** @return string|null the param name if the path has a placeholder. */
    public static function extract_param_name(string $route_path): ?string {
        if (preg_match('#/:([a-z][a-z0-9_]*)(?=/|$)#', $route_path, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function resolve_asset(string $bundle_dir, string $rel_path): ?string {
        if ($rel_path === '' || str_starts_with($rel_path, '/')) {
            return null;
        }
        if (str_contains($rel_path, '..')) {
            return null;
        }
        // Fast path: the install-time asset index already enumerates every
        // legitimate bundle file. A hash-set hit lets us skip realpath()
        // (which makes filesystem syscalls per path segment).
        $index = Bundle::load_asset_index($bundle_dir);
        if ($index !== null) {
            if (!isset($index[$rel_path])) {
                return null;
            }
            $abs = $bundle_dir . '/' . $rel_path;
            return is_file($abs) ? $abs : null;
        }
        // Fallback for bundles installed under an older plugin version
        // that didn't write the sidecar yet.
        $abs = $bundle_dir . '/' . $rel_path;
        $real_bundle = realpath($bundle_dir);
        $real_abs = realpath($abs);
        if ($real_abs === false || $real_bundle === false) {
            return null;
        }
        if (!str_starts_with($real_abs, $real_bundle . DIRECTORY_SEPARATOR) && $real_abs !== $real_bundle) {
            return null;
        }
        if (!is_file($real_abs)) {
            return null;
        }
        return $real_abs;
    }

    public static function mime_type(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'html' => 'text/html; charset=utf-8',
            'htm'  => 'text/html; charset=utf-8',
            'js'   => 'application/javascript',
            'mjs'  => 'application/javascript',
            'css'  => 'text/css',
            'json' => 'application/json',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'txt'  => 'text/plain; charset=utf-8',
            'md'   => 'text/markdown; charset=utf-8',
            'map'  => 'application/json',
            // WebAssembly: browsers enforce `application/wasm` for
            // streaming compilation. `.wat` is the human-readable text
            // format (served as plain text); `.data` filesystem snapshots
            // fall through to the octet-stream default, which is correct.
            'wasm' => 'application/wasm',
            'wat'  => 'text/plain; charset=utf-8',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        // RFC 8615 well-known URIs are routinely extensionless; the api-catalog
        // (RFC 9727) is served as a linkset document.
        if ($ext === '' && basename($filename) === 'api-catalog') {
            return 'application/linkset+json';
        }
        return 'application/octet-stream';
    }

    /**
     * The `Link: rel="api-catalog"` header value for a route response, or null
     * when it should not be emitted. RFC 8615 requires `.well-known` resources
     * at the origin root, so this only fires for root-mounted apps, and only
     * when the bundle actually ships `.well-known/api-catalog`.
     */
    public static function api_catalog_link_header(Manifest $manifest, string $bundle_dir): ?string {
        if ($manifest->mount_mode !== MountMode::Root) {
            return null;
        }
        $index = Bundle::load_asset_index($bundle_dir);
        if ($index === null || !isset($index['.well-known/api-catalog'])) {
            return null;
        }
        return '</.well-known/api-catalog>; rel="api-catalog"';
    }

    /**
     * True when the client explicitly prefers `text/markdown` over `text/html`.
     * Wildcard ranges (star/star, text/star) do NOT count as markdown — only an
     * exact `text/markdown` media range does. Browsers never send `text/markdown`,
     * so they always get HTML; agents that send `Accept: text/markdown` get markdown.
     */
    public static function prefers_markdown(string $accept): bool {
        if ($accept === '') {
            return false;
        }
        $md_q   = -1.0;
        $html_q = 0.0;
        foreach (explode(',', $accept) as $part) {
            $segments = explode(';', trim($part));
            $type = strtolower(trim($segments[0]));
            $q = 1.0;
            foreach (array_slice($segments, 1) as $param) {
                $param = trim($param);
                if (stripos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }
            if ($type === 'text/markdown') {
                $md_q = max($md_q, $q);
            } elseif ($type === 'text/html') {
                $html_q = max($html_q, $q);
            }
        }
        return $md_q > 0.0 && $md_q >= $html_q;
    }

    /**
     * The bundle-relative `.md` sibling for a static route's `file`, or null when
     * the file is not an `.html`/`.htm` document. Does not check existence — the
     * caller resolves it against the asset index.
     */
    public static function markdown_sibling(string $route_file): ?string {
        foreach (['.html', '.htm'] as $ext) {
            if (str_ends_with($route_file, $ext)) {
                return substr($route_file, 0, -strlen($ext)) . '.md';
            }
        }
        return null;
    }

    /**
     * @param array{appId:string, mode:string, routePath:string, locale?:string} $context
     */
    public static function render_route(
        string $bundle_dir,
        Manifest $manifest,
        array $route,
        array $context,
        string $nonce,
    ): string {
        $abs = $bundle_dir . '/' . $route['file'];
        if (!is_file($abs)) {
            return '';
        }
        $html = file_get_contents($abs) ?: '';
        return self::finalize_html($html, $bundle_dir, $manifest, $context, $nonce);
    }

    /**
     * Pipeline shared by inline-mode route renders and publisher-host renders:
     * rewrite asset paths, sanitize HTML, stamp per-request nonce on existing
     * <script>/<style> tags. Does NOT inject the dsgo-context hydration script
     * or any bridge bootstrap; callers append the appropriate tail.
     */
    private static function finalize_html_common(
        string $html,
        string $bundle_dir,
        Manifest $manifest,
        string $nonce,
    ): string {
        $html = self::rewrite_bundle_asset_paths($html, $bundle_dir, $manifest);
        $html = HtmlSanitizer::sanitize($html, [
            'nonce'              => $nonce,
            'allow_root_paths'   => $manifest->mount_mode === MountMode::Root,
            'allow_url_prefix'   => $manifest->mount_mode === MountMode::Root ? null : self::url_prefix_for($manifest),
            'stylesheet_origins' => $manifest->csp['style_src'] ?? [],
            'script_origins'     => $manifest->csp['script_src'] ?? [],
            'embed_origins'      => $manifest->embeds,
        ]);
        return self::stamp_nonce_on_existing_tags($html, $nonce);
    }

    /**
     * Inline-mode route finalize: common pipeline + dsgo-context hydration +
     * inline bridge bootstrap. Both static and dynamic routes call this.
     */
    private static function finalize_html(
        string $html,
        string $bundle_dir,
        Manifest $manifest,
        array $context,
        string $nonce,
    ): string {
        $html = self::finalize_html_common($html, $bundle_dir, $manifest, $nonce);

        $hydration = '<script type="application/json" id="dsgo-context">' .
            wp_json_encode(['bridgeVersion' => 1] + $context) .
            '</script>';
        $bootstrap = self::bridge_bootstrap_html($manifest, $nonce);

        if (preg_match('#</head>#i', $html)) {
            $html = preg_replace('#</head>#i', $hydration . $bootstrap . '</head>', $html, 1) ?? $html;
        } elseif (preg_match('#</body>#i', $html)) {
            $html = preg_replace('#</body>#i', $hydration . $bootstrap . '</body>', $html, 1) ?? $html;
        } else {
            $html = $hydration . $bootstrap . $html;
        }
        return $html;
    }

    /**
     * Build the publisher-host HTML for an inline-mode app that publishes
     * abilities. Emits the manifest's entry HTML through the shared content
     * pipeline (rewrite + sanitize + nonce stamp), then injects ONLY the
     * iframe-mode bridge-client.js script. Skips the dsgo-context JSON island
     * so client.ts auto-detects iframe transport when loaded into the
     * publisher's hidden iframe.
     *
     * Caller is responsible for sending response headers (CSP, etc.) and
     * status code; this method returns the HTML body.
     */
    public static function render_publisher_host(string $bundle_dir, Manifest $manifest, string $nonce): string {
        $entry_abs = $bundle_dir . '/' . $manifest->entry;
        $template = is_file($entry_abs) ? (file_get_contents($entry_abs) ?: '') : '';
        if ($template === '') {
            return '';
        }

        $html = self::finalize_html_common($template, $bundle_dir, $manifest, $nonce);

        $client_path = DSGO_APPS_PATH . 'assets/bridge-client.js';
        $client_ver  = file_exists($client_path) ? (string) filemtime($client_path) : DSGO_APPS_VERSION;
        $client_url  = add_query_arg('ver', $client_ver, plugins_url('assets/bridge-client.js', DSGO_APPS_FILE));
        // Bundle-output script tag (nonce-bound, served as page HTML); cannot be enqueued.
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
        $client_tag = '<script src="' . esc_url($client_url) . '" nonce="' . esc_attr($nonce) . '"></script>';

        if (preg_match('#</head>#i', $html)) {
            $html = preg_replace('#</head>#i', $client_tag . '</head>', $html, 1) ?? $html;
        } elseif (preg_match('#</body>#i', $html)) {
            $html = preg_replace('#</body>#i', $client_tag . '</body>', $html, 1) ?? $html;
        } else {
            $html = $client_tag . $html;
        }
        return $html;
    }

    /**
     * Build the publisher-host response (status + headers + body) for an
     * inline-mode app. Returns the data; does not call header()/echo/exit.
     * The HTTP-emitting wrapper (stream_publisher_host) reads this and writes
     * out the response.
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function dispatch_publisher_host(string $bundle_dir, Manifest $manifest): array {
        if ($manifest->abilities_publishes === []) {
            return ['status' => 404, 'headers' => [], 'body' => ''];
        }

        $entry_abs = $bundle_dir . '/' . $manifest->entry;
        if (!is_file($entry_abs)) {
            return ['status' => 500, 'headers' => [], 'body' => ''];
        }

        $nonce = self::generate_nonce();
        $body  = self::render_publisher_host($bundle_dir, $manifest, $nonce);
        $headers = [
            'Content-Security-Policy' => CSPBuilder::build(self::csp_with_content_origins($manifest), $nonce, $manifest->embeds),
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Cache-Control'           => 'no-store, private',
            'Content-Type'            => 'text/html; charset=UTF-8',
        ];
        return ['status' => 200, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Build the effective CSP source map for the manifest, augmenting
     * `img_src` with the WP uploads root (and gravatar for `user` reads)
     * when the app's permissions return image URLs from site content.
     *
     * @return array{script_src:string[], style_src:string[], img_src:string[], connect_src:string[], font_src?:string[]}
     */
    private static function csp_with_content_origins(Manifest $manifest): array {
        $csp   = $manifest->csp;
        $extra = CSPBuilder::content_image_origins($manifest);
        if ($extra !== []) {
            $csp['img_src'] = array_values(array_unique(array_merge($csp['img_src'] ?? [], $extra)));
        }
        return $csp;
    }

    /**
     * CSP for a route response. Same as csp_with_content_origins, plus the
     * gravatar host when the admin bar is going to render — without it the
     * bar's avatar 404s under strict img-src. Applies to every wrap mode
     * because we now inject the admin bar into wrap: "none" responses too.
     * Apps that already declare a `user` read permission already include
     * gravatar; the array_unique keeps that idempotent.
     *
     * @return array{script_src:string[], style_src:string[], img_src:string[], connect_src:string[], font_src?:string[]}
     */
    private static function csp_for_route_response(Manifest $manifest): array {
        $csp = self::csp_with_content_origins($manifest);
        if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) {
            $csp['img_src'] = array_values(array_unique(array_merge(
                $csp['img_src'] ?? [],
                ['https://secure.gravatar.com'],
            )));
        }
        return $csp;
    }

    /**
     * Inject the WP admin bar into a fully-rendered app document. Used by
     * wrap: "none" responses, where wp_head / wp_footer never fire and the
     * bar would otherwise have no hook to attach to. Returns $html unchanged
     * when the bar isn't showing for the current request (logged-out users,
     * users who've disabled their toolbar, etc.).
     *
     * The bar's external stylesheet + script come from /wp-includes/ and pass
     * CSP under script-src/style-src 'self'. The inline bump-margin style and
     * any plugin-injected inline tags inside the bar HTML are nonce-stamped
     * so strict script-src doesn't kill them.
     */
    private static function inject_admin_bar(string $html, string $nonce): string {
        if (!function_exists('is_admin_bar_showing') || !is_admin_bar_showing()) {
            return $html;
        }
        if (!function_exists('wp_admin_bar_render')) {
            return $html;
        }
        ob_start();
        wp_admin_bar_render();
        $bar = (string) ob_get_clean();
        if ($bar === '') {
            return $html;
        }
        $bar = self::stamp_nonce_on_existing_tags($bar, $nonce);

        // The admin-bar's CSS+JS are emitted inline into the rendered page HTML
        // (with a per-request CSP nonce) rather than enqueued, because this is
        // a bundle-output context, not a normal WP template.
        //
        // WP's `admin-bar` script handle declares `hoverintent-js` as a dep,
        // and the `admin-bar` style handle declares `dashicons` as a dep. We
        // emit both deps inline before the admin-bar tags so `admin-bar.min.js`
        // can find `jQuery.hoverIntent` at runtime (otherwise it throws
        // "l.hoverintent is not a function") and the toolbar's icons render
        // instead of showing as empty squares.
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
        $dashicons_css = '<link rel="stylesheet" href="' . esc_url(includes_url('css/dashicons.min.css')) . '">';
        $bar_css       = '<link rel="stylesheet" href="' . esc_url(includes_url('css/admin-bar.min.css')) . '">';
        $bar_bump = '<style nonce="' . esc_attr($nonce) . '">'
            . 'html{margin-top:32px!important;}'
            . '@media screen and (max-width:782px){html{margin-top:46px!important;}}'
            . '</style>';
        $hoverintent_js = '<script src="' . esc_url(includes_url('js/hoverintent-js.min.js')) . '" nonce="' . esc_attr($nonce) . '"></script>';
        $bar_js         = '<script src="' . esc_url(includes_url('js/admin-bar.min.js')) . '" nonce="' . esc_attr($nonce) . '"></script>';
        // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
        $head_tags = $dashicons_css . $bar_css . $bar_bump;
        $body_tags = $hoverintent_js . $bar_js;

        if (preg_match('#</head>#i', $html)) {
            $html = preg_replace('#</head>#i', $head_tags . '</head>', $html, 1) ?? $html;
        } else {
            $html = $head_tags . $html;
        }
        if (preg_match('#</body>#i', $html)) {
            $html = preg_replace('#</body>#i', $bar . $body_tags . '</body>', $html, 1) ?? $html;
        } else {
            $html .= $bar . $body_tags;
        }
        return $html;
    }

    /**
     * HTTP wrapper for dispatch_publisher_host. Sends headers and body, then
     * exits. Called from maybe_dispatch / maybe_dispatch_root.
     */
    private static function stream_publisher_host(string $bundle_dir, Manifest $manifest): void {
        $resp = self::dispatch_publisher_host($bundle_dir, $manifest);
        status_header($resp['status']);
        foreach ($resp['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        // Body is HTML produced by dispatch_publisher_host(), which already runs
        // through HtmlSanitizer + asset-path rewrite. Echoing pre-escaped HTML.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $resp['body'];
    }

    /**
     * Render a route whose path contains a `:param` placeholder. Returns null
     * to signal a 404 (no entry matches the captured param).
     *
     * @param array{path:string, file:string, dataset:array{source:string,id_field:string}} $route
     * @param array<string,string> $params
     */
    public static function render_dynamic_route(
        string $bundle_dir,
        Manifest $manifest,
        array $route,
        array $params,
        array $context,
        string $nonce,
    ): ?string {
        $param_name = self::extract_param_name($route['path']);
        if ($param_name === null) {
            return null;
        }
        $param_value = $params[$param_name] ?? '';
        if ($param_value === '') {
            return null;
        }

        $version    = self::cache_version($manifest->id);
        $route_hash = md5($route['path']);
        $value_hash = md5($param_value);
        $cache_key  = "dsgo_rd:{$manifest->id}:{$version}:{$route_hash}:{$value_hash}";

        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $dataset = self::load_dataset($bundle_dir, $manifest->id, $route, $manifest);
        $entry   = self::find_entry($dataset, $route['dataset']['id_field'], $param_value);
        if ($entry === null) {
            return null; // 404
        }

        $abs = $bundle_dir . '/' . $route['file'];
        $template = is_file($abs) ? (file_get_contents($abs) ?: '') : '';
        $substituted = self::substitute($template, $entry);
        $rendered = self::finalize_html($substituted, $bundle_dir, $manifest, $context, $nonce);

        set_transient($cache_key, $rendered, 15 * MINUTE_IN_SECONDS);
        return $rendered;
    }

    /**
     * Build the script chain that wires up the same-window bridge transport:
     *   wpApiSettings → wp-api-fetch deps → __dsgoBridgeDeps → parent-bridge-inline → bridge-client-inline.
     *
     * Tag ordering is load-bearing: parent-bridge-inline reads
     * `window.__dsgoBridgeDeps` synchronously at script-execution time, so the
     * bootstrap snippet that assigns it must run before parent-bridge-inline.js
     * loads, and parent-bridge-inline must register its message listener before
     * bridge-client-inline.js can post any requests.
     */
    // The inline bridge runtime must be emitted as inline <script> tags inside
    // the rendered app document with strict CSP nonces and a load-bearing
    // serial execution order (window.__dsgoBridgeDeps read synchronously by
    // parent-bridge-inline). wp_enqueue_script() can't satisfy any of those
    // constraints — registered scripts can't bypass the per-document CSP and
    // can't guarantee nonce attachment to the inline assemble snippet.
    // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
    private static function bridge_bootstrap_html(Manifest $manifest, string $nonce): string {
        $manifest_pub = IframeLoader::manifest_public_view($manifest->to_array());
        $perm_map     = Permissions::to_array();
        $rest_nonce   = wp_create_nonce('wp_rest');
        $rest_root    = esc_url_raw(rest_url());
        // Per-(user, app) nonce — RestApi::permit_storage requires this on
        // every storage call so a malicious app at /apps/A can't read or
        // write app B's storage with a bare fetch() bypass.
        $app_nonce = wp_create_nonce(RestApi::app_nonce_action(get_current_user_id(), $manifest->id));

        $globals = sprintf(
            '<script nonce="%s">window.wpApiSettings=%s;</script>',
            esc_attr($nonce),
            wp_json_encode(['root' => $rest_root, 'nonce' => $rest_nonce]),
        );

        $deps_urls = [
            includes_url('js/dist/hooks.min.js'),
            includes_url('js/dist/i18n.min.js'),
            includes_url('js/dist/url.min.js'),
            includes_url('js/dist/api-fetch.min.js'),
        ];
        $host_path   = DSGO_APPS_PATH . 'assets/parent-bridge-inline.js';
        $client_path = DSGO_APPS_PATH . 'assets/bridge-client-inline.js';
        $host_ver    = file_exists($host_path)   ? (string) filemtime($host_path)   : DSGO_APPS_VERSION;
        $client_ver2 = file_exists($client_path) ? (string) filemtime($client_path) : DSGO_APPS_VERSION;
        $host_url   = add_query_arg('ver', $host_ver,    plugins_url('assets/parent-bridge-inline.js', DSGO_APPS_FILE));
        $client_url = add_query_arg('ver', $client_ver2, plugins_url('assets/bridge-client-inline.js', DSGO_APPS_FILE));

        // Preload the entire script chain so the browser starts fetching
        // every URL in parallel instead of discovering each one when the
        // previous synchronous tag finishes loading. Execution order is
        // still strictly serial (we deliberately can't `defer` because the
        // assemble snippet between deps and the bridge is inline and reads
        // window.wp.apiFetch synchronously) — preloading collapses the
        // round-trip waterfall without changing execution semantics.
        $preloads = '';
        foreach (array_merge($deps_urls, [$host_url, $client_url]) as $url) {
            $preloads .= '<link rel="preload" as="script" href="' . esc_url($url) . '">';
        }

        $deps = '';
        foreach ($deps_urls as $url) {
            $deps .= '<script src="' . esc_url($url) . '" nonce="' . esc_attr($nonce) . '"></script>';
        }

        $assemble = sprintf(
            '<script nonce="%s">(function(){var af=window.wp&&window.wp.apiFetch;if(!af)return;'
                . 'af.use(af.createRootURLMiddleware(window.wpApiSettings.root));'
                . 'af.use(af.createNonceMiddleware(window.wpApiSettings.nonce));'
                . 'window.__dsgoBridgeDeps={manifest:%s,permMap:%s,nonce:window.wpApiSettings.nonce,appNonce:%s,apiFetch:af};'
                . '})();</script>',
            esc_attr($nonce),
            wp_json_encode($manifest_pub),
            wp_json_encode($perm_map),
            wp_json_encode($app_nonce),
        );

        $host   = '<script src="' . esc_url($host_url) . '" nonce="' . esc_attr($nonce) . '"></script>';
        $client = '<script src="' . esc_url($client_url) . '" nonce="' . esc_attr($nonce) . '"></script>';

        return $preloads . $globals . $deps . $assemble . $host . $client;
    }
    // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

    public static function generate_nonce(): string {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Compute the URL prefix path under which an app's bundle assets are
     * served, sans trailing slash. For prefixed mounts: `/{prefix}/{slug}`.
     * For root mounts: empty string (the app owns the site root, so
     * site-absolute paths are already bundle-relative).
     */
    public static function url_prefix_for(Manifest $manifest): string {
        if ($manifest->mount_mode === MountMode::Root) {
            return '';
        }
        return Settings::app_base_path($manifest->id);
    }

    /**
     * Rewrite site-absolute `src` and `href` attributes that target either
     *   - a real file in the bundle (assets like `/_astro/foo.js`), or
     *   - a declared route in `manifest.routes` (anchor navigation between
     *     pages of a multi-route inline app, e.g. `<a href="/about">`).
     *
     * **Prefixed mount.** Routes and HTML files keep the
     * `/{prefix}/{appId}` prefix so PHP can inject the per-request CSP
     * nonce and bridge bootstrap. Non-HTML static assets (`.js`, `.css`,
     * images, fonts, etc.) are rewritten to their actual upload URL
     * (`/wp-content/uploads/designsetgo-apps/<id>/asset.css`) so the host's web
     * server can serve them directly without going through PHP. This avoids
     * managed-host nginx fast-paths (GoDaddy MWP, WP Engine, etc.) that
     * 404 any URL ending in a known static extension before WordPress can
     * run. `<script src="/_astro/foo.js">` becomes
     * `<script src="/wp-content/uploads/designsetgo-apps/<id>/_astro/foo.js">`.
     *
     * **Root mount.** Routes are left as site-absolute paths (the
     * dispatcher catches them on `is_404()` at template_redirect). Assets
     * are rewritten to the bundle's static upload URL using the same
     * nginx-bypass logic as prefixed mounts.
     *
     * Paths that match nothing in the bundle are left alone, so links to
     * WP pages, fragments, and external destinations pass through
     * untouched.
     */
    public static function rewrite_bundle_asset_paths(
        string $html,
        string $bundle_dir,
        Manifest $manifest,
    ): string {
        $prefix     = self::url_prefix_for($manifest);
        $is_root    = $prefix === '';
        // Upload base path (e.g. `/wp-content/uploads/designsetgo-apps/<id>`) used to
        // rewrite static non-HTML assets for both root and prefixed mounts so
        // the host web server can serve them directly without going through PHP.
        // The sanitizer accepts site-absolute paths under this prefix; full
        // origin URLs would be rejected as `remote_link_stylesheet`.
        $upload_base = rtrim((string) (wp_parse_url(Bundle::url_for($manifest->id), PHP_URL_PATH) ?: ''), '/');
        // For prefixed mounts, the route-prefix path is used for routes and HTML
        // files (they need PHP for per-request CSP nonce + bridge injection).
        $route_prefix = $is_root ? '' : $prefix;

        // Collect declared route paths (e.g. ['/', '/about', '/pricing']) so
        // anchors like `<a href="/about">` can be rewritten even though
        // `/about` doesn't correspond to a literal `/about` file on disk.
        $routes = [];
        foreach ($manifest->routes as $r) {
            if (isset($r['path']) && is_string($r['path'])) {
                $routes[$r['path']] = true;
            }
        }
        // Prefer the install-time asset index (hash-set lookup); fall back
        // to is_file() only when the sidecar is missing (e.g., a bundle
        // installed under an older plugin version).
        $index = Bundle::load_asset_index($bundle_dir);
        $is_bundle_file = $index !== null
            ? static fn(string $rel): bool => isset($index[$rel])
            : static fn(string $rel) => $rel !== '' && !str_contains($rel, '..') && is_file($bundle_dir . '/' . $rel);

        $tag_re = '#<(a|script|link|img|source|audio|video|iframe)\b([^>]*)>#i';
        return preg_replace_callback($tag_re, function (array $m) use ($prefix, $is_root, $upload_base, $route_prefix, $routes, $is_bundle_file, $bundle_dir): string {
            $tag   = strtolower($m[1]);
            $attrs = $m[2];
            // Anchors rewrite `href`; <video> also exposes `poster` to a still
            // image; everything else uses `src`/`href`.
            $candidate_attrs = $tag === 'a'
                ? ['href']
                : ($tag === 'video' ? ['src', 'href', 'poster'] : ['src', 'href']);
            foreach ($candidate_attrs as $attr) {
                $val = self::extract_attr_local($attrs, $attr);
                if ($val === null || $val === '') continue;
                if (!str_starts_with($val, '/')) continue;                                  // relative path — skip
                if (str_starts_with($val, '//')) continue;                                  // protocol-relative
                if ($prefix !== '' && (str_starts_with($val, $prefix . '/') || $val === $prefix)) continue; // already mapped (prefixed route)
                if ($upload_base !== '' && str_starts_with($val, $upload_base . '/')) continue;             // already mapped (upload static)
                if (str_starts_with($val, '/wp-admin/')   ||
                    str_starts_with($val, '/wp-includes/')) continue;                       // explicit WP paths
                if (str_starts_with($val, '/wp-content/')) continue;                        // /wp-content/ untouched
                // Strip query/fragment for the on-disk lookup; use ~ delimiters
                // because `#` would conflict with `[?#]` inside the class.
                $path        = preg_replace('~[?#].*$~', '', $val) ?? $val;
                $rel         = ltrim($path, '/');
                $is_route    = isset($routes[$path]);
                $is_bundle   = $rel !== '' && !str_contains($rel, '..') && $is_bundle_file($rel);
                if (!$is_route && !$is_bundle) continue;

                // Routes and HTML files must go through PHP (per-request nonce +
                // bridge injection). All other static bundle files are rewritten
                // to their upload URL so the host web server can serve them
                // directly, bypassing managed-host nginx fast-paths that would
                // 404 known static extensions before WordPress can run.
                if ($is_route || preg_match('#\.html?$#i', $path)) {
                    if ($is_root) continue; // root routes stay as site-absolute paths
                    $new_val = $route_prefix . $val;
                } else {
                    // Cache-bust static asset URLs by per-file mtime. Without
                    // this, browsers cache `styles.css` for the full 31-day
                    // `Cache-Control: max-age=2678400` window the host serves,
                    // and visitors keep seeing stale CSS/JS even after a redeploy
                    // because the URL is byte-identical. mtime advances on every
                    // bundle replace, so the URL changes and caches invalidate.
                    $new_val = $upload_base . $val;
                    $mtime   = @filemtime($bundle_dir . '/' . $rel);
                    if ($mtime !== false) {
                        $sep      = (strpos($new_val, '?') === false) ? '?' : '&';
                        $new_val .= $sep . 'v=' . $mtime;
                    }
                }
                // Rewrite the attribute value while preserving the author's
                // original quoting style (`"`, `'`, or unquoted). Matching
                // double-quoted only would silently no-op on single-quoted
                // bundle paths and they would be left pointing at routes the
                // host web server can't resolve.
                $attrs = preg_replace_callback(
                    '#(\b' . preg_quote($attr, '#') . '\s*=\s*)'
                    . '(?:"' . preg_quote($val, '#') . '"'
                    . '|\'' . preg_quote($val, '#') . '\''
                    . '|(' . preg_quote($val, '#') . ')(?=[\s>]|$))#',
                    static function (array $m) use ($new_val): string {
                        // $m[1] is the `name=` prefix; the byte right after
                        // tells us how the original value was quoted.
                        $after_eq = substr($m[0], strlen($m[1]), 1);
                        if ($after_eq === '"') return $m[1] . '"' . $new_val . '"';
                        if ($after_eq === "'") return $m[1] . "'" . $new_val . "'";
                        return $m[1] . $new_val;
                    },
                    $attrs,
                    1,
                ) ?? $attrs;
            }
            return '<' . $m[1] . $attrs . '>';
        }, $html) ?? $html;
    }

    private static function stamp_nonce_on_existing_tags(string $html, string $nonce): string {
        $html = preg_replace_callback(
            '#<script\b([^>]*)>#i',
            function (array $m) use ($nonce): string {
                $attrs = $m[1];
                $type = self::extract_attr_local($attrs, 'type') ?? '';
                if (in_array(strtolower($type), ['application/json', 'speculationrules', 'importmap'], true)) {
                    return $m[0];
                }
                if (preg_match('#\bnonce\s*=#i', $attrs)) {
                    return $m[0];
                }
                // Stamp the per-request nonce on every executable <script> —
                // both `<script src=...>` and inline `<script>...body...</script>`.
                // Without this, framework-emitted inline modules (Astro / Next
                // hydration shims) would be blocked by CSP at the browser.
                return '<script' . rtrim($attrs) . ' nonce="' . esc_attr($nonce) . '">';
            },
            $html,
        ) ?? $html;
        $html = preg_replace_callback(
            '#<style\b([^>]*)>#i',
            function (array $m) use ($nonce): string {
                if (preg_match('#\bnonce\s*=#i', $m[1])) {
                    return $m[0];
                }
                return '<style' . rtrim($m[1]) . ' nonce="' . esc_attr($nonce) . '">';
            },
            $html,
        ) ?? $html;
        return $html;
    }

    private static function extract_attr_local(string $attrs, string $name): ?string {
        // Match all three HTML5 attribute-value forms (double-quoted,
        // single-quoted, unquoted). Mirrors HtmlSanitizer::extract_attr;
        // see that method's comment for context. Without this, the URL
        // rewriter and nonce-stamper silently skipped any attribute whose
        // value wasn't double-quoted.
        $pattern = '#\b' . preg_quote($name, '#')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';
        if (preg_match($pattern, $attrs, $m)) {
            if (isset($m[1]) && $m[1] !== '') return $m[1];
            if (isset($m[2]) && $m[2] !== '') return $m[2];
            if (isset($m[3]) && $m[3] !== '') return $m[3];
            return '';
        }
        return null;
    }

    public static function extract_body_content(string $html): string {
        if (preg_match('#<body[^>]*>(.*?)</body>#is', $html, $m)) {
            return $m[1];
        }
        return $html;
    }

    public static function inject_route_meta(?string $title, ?string $description): callable {
        $title_filter = $title === null ? null : function (array $parts) use ($title) {
            $parts['title'] = $title;
            return $parts;
        };
        $head_action = $description === null ? null : function () use ($description) {
            echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        };
        if ($title_filter) add_filter('document_title_parts', $title_filter);
        if ($head_action) add_action('wp_head', $head_action);
        return function () use ($title_filter, $head_action) {
            if ($title_filter) remove_filter('document_title_parts', $title_filter);
            if ($head_action) remove_action('wp_head', $head_action);
        };
    }

    private static function normalize_path(string $path): string {
        if ($path === '/' || $path === '') return '/';
        return rtrim($path, '/');
    }

    // -------------------------------------------------------------------------
    // Request dispatcher (hooked to template_redirect at priority 5)
    // -------------------------------------------------------------------------

    /**
     * If the request prefers markdown and the matched static route has a `.md`
     * sibling in the bundle, stream that sibling and return true (caller exits).
     * Dynamic routes (path contains `:`) are never negotiated — they have no
     * static `file`. Returns false to fall through to the HTML render path.
     */
    private static function maybe_stream_markdown(string $bundle_dir, array $route): bool {
        if (str_contains((string) ($route['path'] ?? ''), ':')) {
            return false;
        }
        $accept = isset($_SERVER['HTTP_ACCEPT'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT']))
            : '';
        if (!self::prefers_markdown($accept)) {
            return false;
        }
        $sibling = self::markdown_sibling((string) ($route['file'] ?? ''));
        if ($sibling === null) {
            return false;
        }
        $md_abs = self::resolve_asset($bundle_dir, $sibling);
        if ($md_abs === null) {
            return false;
        }
        if (!headers_sent()) {
            status_header(200);
        }
        self::stream_asset($md_abs);
        return true;
    }

    public static function maybe_dispatch(): void {
        $app_id = get_query_var(Rewrite::QUERY_VAR);
        if ($app_id === '' || $app_id === null) {
            return;
        }
        $route_path = (string) get_query_var(Rewrite::ROUTE_PATH_VAR);
        $route_path = $route_path === '' ? '/' : '/' . ltrim($route_path, '/');

        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            IframeLoader::render((string) $app_id);
            exit;
        }
        $raw_manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw_manifest)) {
            IframeLoader::render((string) $app_id);
            exit;
        }

        // Manifests in post meta were validated at install time; skip
        // re-validation on the hot path.
        $manifest = Manifest::from_array_unchecked($raw_manifest);

        if ($manifest->isolation !== 'inline') {
            return;
        }

        $bundle_dir = self::bundle_dir_for($manifest->id);

        if ($route_path === '/__dsgo-host') {
            self::stream_publisher_host($bundle_dir, $manifest);
            exit;
        }

        $resolved = self::resolve_route($manifest, $route_path);
        if ($resolved !== null) {
            [$route, $params] = $resolved;
            if (self::maybe_stream_markdown($bundle_dir, $route)) {
                exit;
            }
            self::stream_route($bundle_dir, $manifest, $route, $params);
            exit;
        }

        $asset_rel = ltrim($route_path, '/');
        $asset_abs = self::resolve_asset($bundle_dir, $asset_rel);
        if ($asset_abs !== null) {
            self::stream_asset($asset_abs);
            exit;
        }

        self::render_route_not_found($manifest);
        exit;
    }

    private static function render_route_not_found(Manifest $manifest): void {
        if (!headers_sent()) {
            nocache_headers();
            status_header(404);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        // Prepare the presentation data; the markup (theme-wrapped body +
        // self-contained fallback document) lives in the template partial.
        // phpcs:disable WordPress.PHP.DontExtract
        $ctx = [
            'title'          => __('Page not found', 'designsetgo-apps'),
            'body'           => sprintf(
                /* translators: %s: app name */
                __('That route is not part of "%s".', 'designsetgo-apps'),
                $manifest->name,
            ),
            'home_link'      => '<a href="' . esc_url(home_url('/')) . '">'
                . esc_html__('← Return to site', 'designsetgo-apps') . '</a>',
            'lang'           => esc_attr(str_replace('_', '-', get_locale())),
            'can_theme_wrap' => $manifest->theme_wrap === 'header_footer'
                && function_exists('get_header')
                && function_exists('get_footer'),
        ];
        // phpcs:enable WordPress.PHP.DontExtract
        require DSGO_APPS_PATH . 'templates/route-not-found.php';
    }

    /**
     * Root-mount dispatcher (hooked to template_redirect at priority 7).
     *
     * Runs after `maybe_dispatch` so prefixed-mount apps win when the rewrite
     * matched. Acts when there's a single root-mounted app and either (a) WP
     * did not find a real object to serve, or (b) the requested path resolves
     * to a route declaring `claim: "always"`. Without the claim opt-in, real
     * WP pages, posts, archives, feeds, search, etc. always win over root-app
     * routes; with the opt-in, the app wins at that specific path.
     */
    public static function maybe_dispatch_root(): void {
        // Cheapest checks first — we want this hook to be a near-noop on the
        // overwhelming majority of requests (no root app installed, or a
        // prefixed-mount request that already dispatched at priority 5).
        $root_slug = Settings::get_root_app_id();
        if ($root_slug === null) {
            return;
        }
        if (get_query_var(Rewrite::QUERY_VAR) !== '' && get_query_var(Rewrite::QUERY_VAR) !== null) {
            return; // prefixed dispatch already handled or about to handle
        }
        // Load the manifest before the path guard so route_claims_path() can
        // check whether the app explicitly claims the requested path. The
        // post/manifest lookup is cheap relative to a full WP template load,
        // and it only runs when a root app is configured (root_slug is set).
        $post = get_page_by_path($root_slug, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            // Cache is stale — drop it and let WP serve its 404.
            Settings::refresh_root_app_id();
            return;
        }
        $raw_manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw_manifest)) {
            return;
        }
        $manifest = Manifest::from_array_unchecked($raw_manifest);
        if ($manifest->isolation !== 'inline' || $manifest->mount_mode !== MountMode::Root) {
            // Cache disagrees with manifest; refresh and skip.
            Settings::refresh_root_app_id();
            return;
        }

        // The root path is always claimed by the root app — that's the
        // entire point of root-mount: the user has chosen `/` to be the
        // app instead of WP's homepage / static front page / blog index.
        // Other paths only fill in WP's 404s so real posts, archives, and
        // single pages keep working, unless the app explicitly claims the
        // path via `claim: "always"`.
        $request_path = self::current_request_path();
        $is_root_path = $request_path === '/';
        // Routes can opt into overriding WP at their own URL via
        // `claim: "always"`. Without that opt-in, the dispatcher only fires
        // on `/` or where WP would 404 — root apps fill in WP's blanks but
        // never shadow real WP content.
        $claims_path = self::route_claims_path($manifest, $request_path);
        if (!$is_root_path && !is_404() && !$claims_path) {
            return;
        }

        $bundle_dir = self::bundle_dir_for($manifest->id);

        if ($request_path === '/__dsgo-host') {
            self::stream_publisher_host($bundle_dir, $manifest);
            exit;
        }

        $resolved = self::resolve_route($manifest, $request_path);
        if ($resolved !== null) {
            [$route, $params] = $resolved;
            if (self::maybe_stream_markdown($bundle_dir, $route)) {
                exit;
            }
            status_header(200);
            self::stream_route($bundle_dir, $manifest, $route, $params);
            exit;
        }

        $asset_rel = ltrim($request_path, '/');
        $asset_abs = self::resolve_asset($bundle_dir, $asset_rel);
        if ($asset_abs !== null) {
            status_header(200);
            self::stream_asset($asset_abs);
            exit;
        }
        // If the manifest declares a `/404` static route, serve it with a 404
        // status so root-mounted apps own the not-found surface (URL stays
        // as-is). Apps without a `/404` route fall through to WP's native
        // 404 template.
        foreach ($manifest->routes as $r) {
            if (($r['path'] ?? null) === '/404') {
                self::emit_404($manifest);
                exit;
            }
        }
        // Fall through — let WP serve its normal 404 template.
    }

    private static function current_request_path(): string {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
        if ($uri === '') $uri = '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        // Trim site subdirectory (e.g. WP installed at /blog/).
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($home_path) && $home_path !== '' && $home_path !== '/' && str_starts_with($path, $home_path)) {
            $path = substr($path, strlen(rtrim($home_path, '/')));
            if ($path === '') $path = '/';
        }
        return $path;
    }

    /**
     * The path *within the app's mount* — what `dsgo.context.path` exposes.
     * For prefixed mounts: strip `/{prefix}/{appId}` from the request path.
     * For root mounts: the request path is already mount-relative.
     */
    private static function app_relative_path(Manifest $manifest): string {
        $request = self::current_request_path();
        $prefix  = self::url_prefix_for($manifest);
        if ($prefix === '') {
            return $request;
        }
        if ($request === $prefix || $request === $prefix . '/') {
            return '/';
        }
        if (str_starts_with($request, $prefix . '/')) {
            $rel = substr($request, strlen($prefix));
            return $rel === '' ? '/' : $rel;
        }
        return '/';
    }

    /**
     * Query string with leading `?` for non-empty, else `""`.
     * Server-derived; matches what `dsgo.context.search` is documented to hold.
     */
    private static function current_search_string(): string {
        $qs = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : '';
        if ($qs === '') {
            return '';
        }
        return '?' . $qs;
    }

    private static function stream_route(string $bundle_dir, Manifest $manifest, array $route, array $params = []): void {
        $nonce   = self::generate_nonce();
        $context = [
            'mode'        => 'inline',
            'appId'       => $manifest->id,
            'routePath'   => $route['path'],
            'routeParams' => (object) $params,
            'path'        => self::app_relative_path($manifest),
            'search'      => self::current_search_string(),
            'hash'        => '',
            'mountPrefix' => self::url_prefix_for($manifest),
            'locale'      => get_locale(),
            'theme'       => 'light',
        ];
        if (in_array(Permission::Ai, $manifest->permissions_read, true)) {
            $context['aiTimeoutSeconds'] = $manifest->ai_timeout_seconds;
        }

        if (str_contains($route['path'], ':')) {
            $rendered = self::render_dynamic_route($bundle_dir, $manifest, $route, $params, $context, $nonce);
            if ($rendered === null) {
                self::emit_404($manifest);
                return;
            }
        } else {
            $rendered = self::render_route($bundle_dir, $manifest, $route, $context, $nonce);
        }

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: interest-cohort=()');
            header('Content-Security-Policy: ' . CSPBuilder::build(self::csp_for_route_response($manifest), $nonce, $manifest->embeds));
            $link = self::api_catalog_link_header($manifest, $bundle_dir);
            if ($link !== null) {
                header('Link: ' . $link, false);
            }
        }

        do_action('dsgo_apps_inline_app_loaded', [
            'app_id'      => $manifest->id,
            'app_post_id' => 0,
            'mode'        => 'inline',
            'user_id'     => get_current_user_id(),
            'referrer'    => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
            'path'        => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ]);

        if ($manifest->theme_wrap === 'header_footer') {
            $body  = self::extract_body_content($rendered);
            $reset = self::inject_route_meta($route['title'] ?? null, $route['description'] ?? null);
            // Buffer header/footer so the per-request CSP nonce can be stamped
            // on inline <script> / <style> tags emitted by wp_head / wp_footer.
            // The admin bar (and most plugins hooked into wp_print_footer_scripts)
            // emit small inline bootstrap snippets — without the nonce, strict
            // script-src blocks them and JS features like the admin bar dropdowns
            // break silently.
            ob_start();
            get_header();
            $header_html = (string) ob_get_clean();
            ob_start();
            get_footer();
            $footer_html = (string) ob_get_clean();
            // Theme output is mutated only by stamp_nonce_on_existing_tags, which
            // adds nonce attributes to existing tags without touching content.
            echo self::stamp_nonce_on_existing_tags($header_html, $nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            // $body is the install-time-validated, render-time-sanitized app body (HtmlSanitizer + render_route pipeline).
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo self::stamp_nonce_on_existing_tags($footer_html, $nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $reset();
        } else {
            // wrap: "none" — bundle owns the full document. Inject the admin
            // bar manually since wp_head/wp_footer never fire on this path.
            $rendered = self::inject_admin_bar($rendered, $nonce);
            // $rendered is a full document produced by render_route() against an install-time-validated bundle.
            echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Emit a real 404 for an unmatched dynamic-route param. Apps that want a
     * custom 404 declare a `/404` static route — that's served via the normal
     * static-route pipeline.
     */
    private static function emit_404(Manifest $manifest): void {
        if (!headers_sent()) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
        }
        $custom = null;
        foreach ($manifest->routes as $r) {
            if ($r['path'] === '/404' && !str_contains($r['path'], ':')) {
                $custom = $r;
                break;
            }
        }
        if ($custom !== null) {
            $bundle_dir = self::bundle_dir_for($manifest->id);
            $nonce      = self::generate_nonce();
            $context    = [
                'mode'        => 'inline',
                'appId'       => $manifest->id,
                'routePath'   => '/404',
                'routeParams' => (object) [],
                'path'        => self::app_relative_path($manifest),
                'search'      => self::current_search_string(),
                'hash'        => '',
                'mountPrefix' => self::url_prefix_for($manifest),
                'locale'      => get_locale(),
                'theme'       => 'light',
            ];
            // render_route returns a document built from install-time-validated bundle + sanitized HTML.
            echo self::render_route($bundle_dir, $manifest, $custom, $context, $nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        echo '<!doctype html><title>Not found</title><h1>Not found</h1>';
    }

    private static function stream_asset(string $abs): void {
        $size = (int) @filesize($abs);
        $mtime = (int) @filemtime($abs);
        // ETag is a strong validator on (size, mtime) — cheap and stable as
        // long as the file isn't rewritten in place. Quote-wrapped per RFC.
        $etag = sprintf('"%x-%x"', $size, $mtime);

        // Hashed-asset heuristic: bundlers fingerprint output filenames
        // (e.g. `_astro/foo.abc123.js`, `assets/index-9d4f2a.css`,
        // `chunks/main.5f1ab8c.js`). When the basename contains an 8+ hex
        // segment we can serve immutable+long-lived; everything else gets
        // a short TTL with revalidation.
        $cache_control = self::is_fingerprinted_asset($abs)
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=300, must-revalidate';

        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_IF_NONE_MATCH'])) : '';
        $client_ims  = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_IF_MODIFIED_SINCE'])) : '';
        $not_modified = ($client_etag !== '' && trim($client_etag) === $etag)
            || ($client_ims !== '' && $mtime > 0 && strtotime((string) $client_ims) >= $mtime);

        if (!headers_sent()) {
            header('Content-Type: ' . self::mime_type($abs));
            header('Cache-Control: ' . $cache_control);
            header('X-Content-Type-Options: nosniff');
            header('ETag: ' . $etag);
            if ($mtime > 0) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            }
        }
        if ($not_modified) {
            status_header(304);
            return;
        }
        // Streaming a static asset to the response. WP_Filesystem has no
        // streaming equivalent (its get_contents() reads into memory, breaking
        // large bundles) and the path is bounded inside the install dir by
        // resolve_asset(). readfile() is the right tool for the job.
        readfile($abs); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    }

    private static function is_fingerprinted_asset(string $abs): bool {
        $name = basename($abs);
        // Match common bundler patterns: `name.<hex>.ext`, `name-<hex>.ext`,
        // or `<hex>.ext` where <hex> is 8+ hex chars.
        return (bool) preg_match('/[.\-][0-9a-f]{8,}\.[a-z0-9]+$/i', $name)
            || (bool) preg_match('/^[0-9a-f]{8,}\.[a-z0-9]+$/i', $name);
    }

    // -------------------------------------------------------------------------
    // Template substitution (Mustache-subset)
    //
    // - `{{path.to.field}}` — HTML-escaped via esc_html (default).
    // - `{{{path.to.field}}}` — raw; the sanitizer runs after substitution and
    //   strips disallowed tags/attrs, but ALL substitution is skipped inside
    //   <script> / <style> blocks. Apps that need dataset values in client JS
    //   read them from `dsgo.context.routeParams` instead.
    // -------------------------------------------------------------------------

    private const PATH_PATTERN = '[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*';

    public static function substitute(string $template, array $entry): string {
        // 1. Pull <script>/<style> blocks out so substitution can't reach into
        //    JS-string or CSS-property contexts.
        [$stripped, $blocks] = self::extract_blocks($template);

        // 2. Process triple-brace first; replace each match with a sentinel so
        //    the double-brace pass cannot accidentally re-substitute literal
        //    `{{...}}` text that may appear inside a raw value.
        $raw_results = [];
        $stripped = preg_replace_callback(
            '/\{\{\{\s*(' . self::PATH_PATTERN . ')\s*\}\}\}/',
            function (array $m) use ($entry, &$raw_results): string {
                $value = self::render_value(self::lookup($entry, $m[1]), false);
                $idx = count($raw_results);
                $raw_results[] = $value;
                return "\x00DSGO_RAW_{$idx}\x00";
            },
            $stripped,
        ) ?? $stripped;

        // 3. Process double-brace (HTML-escaped).
        $stripped = preg_replace_callback(
            '/\{\{\s*(' . self::PATH_PATTERN . ')\s*\}\}/',
            fn(array $m) => self::render_value(self::lookup($entry, $m[1]), true),
            $stripped,
        ) ?? $stripped;

        // 4. Reinsert raw values in place of their sentinels.
        $stripped = preg_replace_callback(
            '/\x00DSGO_RAW_(\d+)\x00/',
            fn(array $m) => $raw_results[(int) $m[1]] ?? '',
            $stripped,
        ) ?? $stripped;

        // 4b. Strip any `<script>` tag introduced by a raw value. Template
        //     scripts were stashed in $blocks at step 1 and will be restored
        //     verbatim by step 5; anything matching `<script>` here can only
        //     have come from a `{{{field}}}` substitution. The downstream
        //     sanitizer intentionally allows inline scripts so the renderer
        //     can stamp a per-request CSP nonce on them — without this strip,
        //     a script value coming from dataset content (potentially a
        //     lower-trust author) would also get nonce-stamped and execute
        //     in the inline page's WordPress origin.
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $stripped) ?? $stripped;
        // Also strip orphaned opening/closing tags so a value like
        // `<script src=...>` (no closing tag, attacker hopes the parser
        // recovers) leaves nothing exploitable behind.
        $stripped = preg_replace('#</?script\b[^>]*>?#i', '', $stripped) ?? $stripped;

        // 5. Restore the original <script>/<style> blocks unchanged.
        return self::restore_blocks($stripped, $blocks);
    }

    /**
     * @return array{0:string, 1:list<string>}
     */
    private static function extract_blocks(string $html): array {
        $blocks = [];
        $stripped = preg_replace_callback(
            '#<(script|style)\b[^>]*>.*?</\1>#is',
            function (array $m) use (&$blocks): string {
                $idx = count($blocks);
                $blocks[] = $m[0];
                return "\x00DSGO_BLOCK_{$idx}\x00";
            },
            $html,
        ) ?? $html;
        return [$stripped, $blocks];
    }

    private static function restore_blocks(string $html, array $blocks): string {
        return preg_replace_callback(
            '/\x00DSGO_BLOCK_(\d+)\x00/',
            fn(array $m) => $blocks[(int) $m[1]] ?? '',
            $html,
        ) ?? $html;
    }

    private static function lookup(array $entry, string $path): mixed {
        $cur = $entry;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private static function render_value(mixed $value, bool $escape): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return '[object]';
        }
        if (!is_string($value)) {
            return '';
        }
        return $escape ? esc_html($value) : $value;
    }

    // -------------------------------------------------------------------------
    // Per-app cache version stamp + dataset loader.
    //
    // Embedding the version in transient keys lets us invalidate atomically
    // across DB and object-cache backends — bumping the version is one
    // update_option call; no key enumeration needed.
    // -------------------------------------------------------------------------

    public static function cache_version(string $app_id): string {
        $opt = 'dsgo_app_cache_version_' . $app_id;
        $version = get_option($opt);
        if (!is_string($version) || $version === '') {
            $version = wp_generate_uuid4();
            update_option($opt, $version, false);
        }
        return $version;
    }

    public static function bump_cache_version(string $app_id): void {
        update_option('dsgo_app_cache_version_' . $app_id, wp_generate_uuid4(), false);
    }

    /**
     * Read + parse a dataset file, caching the parsed array as a transient.
     * Public so SitemapProvider can share the same parse cost.
     *
     * @param array{path:string, file:string, dataset:array{source:string,id_field:string}} $route
     * @return array<int, array<string, mixed>>
     */
    public static function load_dataset(string $bundle_dir, string $app_id, array $route, ?Manifest $manifest = null): array {
        $version = self::cache_version($app_id);
        $route_hash = md5($route['path']);
        $source = (string) $route['dataset']['source'];

        // Live sources (wp:posts, wp:pages, wp:cpt:<slug>, wc:products, plus
        // any registered via the dsgo_apps_dataset_resolver filter) are
        // resolved at request time from host content. Bundle-relative .json
        // paths fall through to the file reader.
        if (str_contains($source, ':')) {
            // Cache key contract for live sources:
            //   - Built-ins (wp:*, wc:products) return globally consistent
            //     rows, so the default key (app+version+route) is safe.
            //   - Custom resolvers may depend on the current user, locale,
            //     query, etc. They opt in to per-context caching by
            //     returning a non-empty string from the
            //     `dsgo_apps_dataset_cache_key_extra` filter, or opt out
            //     entirely by returning 0 from `dsgo_apps_dataset_cache_ttl`.
            $cache_extra = (string) apply_filters('dsgo_apps_dataset_cache_key_extra', '', $source, $app_id, $route);
            $cache_ttl   = (int) apply_filters('dsgo_apps_dataset_cache_ttl', HOUR_IN_SECONDS, $source, $app_id, $route);
            // Manifest presence changes the row shape (DataSources adds
            // `content_styles` when the manifest opts in), so split the cache
            // so manifest-less callers (e.g. SitemapProvider) can't poison
            // the renderer's cache with style-stripped rows.
            $manifest_suffix = $manifest !== null ? ':m' : '';
            $live_key    = $cache_extra === ''
                ? "dsgo_ds:{$app_id}:{$version}:{$route_hash}{$manifest_suffix}"
                : "dsgo_ds:{$app_id}:{$version}:{$route_hash}:" . md5($cache_extra) . $manifest_suffix;

            if ($cache_ttl > 0) {
                $cached = get_transient($live_key);
                if (is_array($cached)) {
                    return $cached;
                }
            }
            $live = DataSources::resolve($source, $manifest);
            // feature_inactive means the Pro gate is closed; treat as 404 so
            // free sites don't render a half-broken dynamic route. Never cache
            // the gate result — it changes when a license is activated.
            if (is_array($live) && ($live['error'] ?? null) === 'feature_inactive') {
                return [];
            }
            if ($live !== null) {
                if ($cache_ttl > 0) {
                    set_transient($live_key, $live, $cache_ttl);
                }
                return $live;
            }
        }

        // Bundle-file path. The file is immutable for the life of an install,
        // so the default app+version+route key with no resolver context is
        // correct here.
        $cache_key = "dsgo_ds:{$app_id}:{$version}:{$route_hash}";
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $abs = $bundle_dir . '/' . $source;
        $raw = is_readable($abs) ? file_get_contents($abs) : false;
        if ($raw === false) {
            return [];
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [];
        }

        set_transient($cache_key, $parsed, HOUR_IN_SECONDS);
        return $parsed;
    }

    /**
     * Linear scan for the entry whose id_field (string-coerced) equals $value.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public static function find_entry(array $entries, string $id_field, string $value): ?array {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!array_key_exists($id_field, $entry)) {
                continue;
            }
            $candidate = $entry[$id_field];
            if (!is_string($candidate) && !is_int($candidate) && !is_float($candidate)) {
                continue;
            }
            if ((string) $candidate === $value) {
                return $entry;
            }
        }
        return null;
    }

    private static function bundle_dir_for(string $app_id): string {
        $upload = wp_upload_dir();
        return rtrim($upload['basedir'], '/') . '/designsetgo-apps/' . $app_id;
    }
}
