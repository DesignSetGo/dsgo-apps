<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\InlineRenderer;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class InlineRendererTest extends WP_UnitTestCase {

    public function test_resolves_root_route(): void {
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $resolved = InlineRenderer::resolve_route($manifest, '/');
        $this->assertNotNull($resolved);
        [$hit, $params] = $resolved;
        $this->assertSame('/', $hit['path']);
        $this->assertSame('index.html', $hit['file']);
        $this->assertSame([], $params);
    }

    public function test_resolves_named_route(): void {
        $arr = $this->minimal_inline_manifest();
        $arr['routes'][] = ['path' => '/about', 'file' => 'about.html', 'title' => 'About'];
        $manifest = Manifest::validate($arr);
        $resolved = InlineRenderer::resolve_route($manifest, '/about');
        $this->assertNotNull($resolved);
        [$hit] = $resolved;
        $this->assertSame('about.html', $hit['file']);
        $this->assertSame('About', $hit['title']);
    }

    public function test_returns_null_for_unmatched_route(): void {
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $this->assertNull(InlineRenderer::resolve_route($manifest, '/nope'));
    }

    public function test_normalizes_trailing_slash(): void {
        $arr = $this->minimal_inline_manifest();
        $arr['routes'][] = ['path' => '/about', 'file' => 'about.html'];
        $manifest = Manifest::validate($arr);
        $resolved = InlineRenderer::resolve_route($manifest, '/about/');
        $this->assertNotNull($resolved);
        [$hit] = $resolved;
        $this->assertSame('/about', $hit['path']);
    }

    public function test_resolve_asset_path_within_bundle(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-renderer-' . uniqid();
        mkdir($bundle_dir . '/assets', 0755, true);
        file_put_contents($bundle_dir . '/assets/app.js', 'console.log(1);');
        $abs = InlineRenderer::resolve_asset($bundle_dir, 'assets/app.js');
        $this->assertSame(realpath($bundle_dir . '/assets/app.js'), $abs);
    }

    public function test_resolve_asset_rejects_traversal(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-renderer-' . uniqid();
        mkdir($bundle_dir, 0755, true);
        $this->assertNull(InlineRenderer::resolve_asset($bundle_dir, '../etc/passwd'));
        $this->assertNull(InlineRenderer::resolve_asset($bundle_dir, '/etc/passwd'));
        $this->assertNull(InlineRenderer::resolve_asset($bundle_dir, 'a/../../b'));
    }

    public function test_resolve_asset_returns_null_for_missing(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-renderer-' . uniqid();
        mkdir($bundle_dir, 0755, true);
        $this->assertNull(InlineRenderer::resolve_asset($bundle_dir, 'nope.js'));
    }

    public function test_mime_type_for_common_extensions(): void {
        $this->assertSame('text/html; charset=utf-8', InlineRenderer::mime_type('foo.html'));
        $this->assertSame('application/javascript', InlineRenderer::mime_type('app.js'));
        $this->assertSame('text/css', InlineRenderer::mime_type('styles.css'));
        $this->assertSame('image/svg+xml', InlineRenderer::mime_type('logo.svg'));
        $this->assertSame('application/json', InlineRenderer::mime_type('data.json'));
        $this->assertSame('application/octet-stream', InlineRenderer::mime_type('weird.xyz'));
    }

    public function test_render_emits_full_html_for_wrap_none(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-render-' . uniqid();
        mkdir($bundle_dir, 0755, true);
        file_put_contents($bundle_dir . '/index.html',
            '<!doctype html><html><head><title>Hi</title></head><body><h1>Hello</h1></body></html>');
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $route = $manifest->routes[0];
        $context = ['appId' => 'sample-inline', 'mode' => 'inline', 'routePath' => '/', 'routeParams' => (object) [], 'locale' => 'en-US'];

        $output = InlineRenderer::render_route($bundle_dir, $manifest, $route, $context, 'NONCE-FIXED');
        $this->assertStringContainsString('<h1>Hello</h1>', $output);
        $this->assertStringContainsString('id="dsgo-context"', $output);
        $this->assertStringContainsString('"routePath":"\/"', $output);
        $this->assertStringContainsString('"routeParams":{}', $output);
        $this->assertStringContainsString('nonce="NONCE-FIXED"', $output);
        $this->assertStringContainsString('bridge-client-inline.js', $output);
        $this->assertStringContainsString('parent-bridge-inline.js', $output);
        // Host (parent-bridge) must be emitted before the client so its message
        // listener is registered before the client fires its first request.
        // Match the actual <script src=...> tag, not preload <link>s, since
        // the renderer emits both for HTTP/2 parallel fetch.
        $host_script_pos   = strpos($output, '<script src="' . esc_url(plugins_url('assets/parent-bridge-inline.js', DSGO_APPS_FILE)));
        $client_script_pos = strpos($output, '<script src="' . esc_url(plugins_url('assets/bridge-client-inline.js', DSGO_APPS_FILE)));
        $this->assertNotFalse($host_script_pos);
        $this->assertNotFalse($client_script_pos);
        $this->assertLessThan($client_script_pos, $host_script_pos);

        // Bootstrap must wire up wp.apiFetch and assemble window.__dsgoBridgeDeps
        // before parent-bridge-inline.js runs (the host reads deps synchronously
        // at script-execution time).
        $this->assertStringContainsString('window.wpApiSettings', $output);
        $this->assertStringContainsString('api-fetch.min.js', $output);
        $this->assertStringContainsString('window.__dsgoBridgeDeps', $output);
        $deps_pos = strpos($output, 'window.__dsgoBridgeDeps');
        $this->assertNotFalse($deps_pos);
        $this->assertLessThan($host_script_pos, $deps_pos);
    }

    public function test_render_route_html_passes_through_sanitizer(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-render-' . uniqid();
        mkdir($bundle_dir, 0755, true);
        file_put_contents($bundle_dir . '/index.html', '<h1>x</h1><a href="javascript:alert(1)">y</a>');
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $route = $manifest->routes[0];

        $output = InlineRenderer::render_route($bundle_dir, $manifest, $route, [], 'N');
        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringContainsString('<h1>x</h1>', $output);
    }

    public function test_rewrite_bundle_asset_paths_rewrites_existing_files(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-rewrite-' . uniqid();
        mkdir($bundle . '/_astro', 0755, true);
        file_put_contents($bundle . '/_astro/foo.js', '/* x */');
        $arr = $this->minimal_inline_manifest();
        $arr['id'] = 'demo';
        $manifest = Manifest::validate($arr);
        $html = '<script src="/_astro/foo.js"></script>'
              . '<link rel="stylesheet" href="/_astro/foo.js">'
              . '<a href="/contact">x</a>'
              . '<img src="/wp-content/uploads/img.png">';
        $out = InlineRenderer::rewrite_bundle_asset_paths($html, $bundle, $manifest);
        $prefix = InlineRenderer::url_prefix_for($manifest);
        $this->assertStringContainsString('src="' . $prefix . '/_astro/foo.js"', $out);
        $this->assertStringContainsString('href="' . $prefix . '/_astro/foo.js"', $out);
        // Non-bundle paths must be left alone (route link + WP path).
        $this->assertStringContainsString('href="/contact"', $out);
        $this->assertStringContainsString('src="/wp-content/uploads/img.png"', $out);
    }

    public function test_rewrite_bundle_asset_paths_rewrites_anchors_to_declared_routes(): void {
        // Multi-page inline apps use `<a href="/about">` for navigation
        // between routes. Without anchor rewriting, the browser navigates
        // to the WP site root instead of the app's `/about` route.
        $bundle = sys_get_temp_dir() . '/dsgo-anchor-' . uniqid();
        mkdir($bundle, 0755, true);
        file_put_contents($bundle . '/index.html', '<!doctype html><html><head></head><body>x</body></html>');
        file_put_contents($bundle . '/about.html', '<!doctype html><html><head></head><body>about</body></html>');
        $arr = $this->minimal_inline_manifest();
        $arr['id']     = 'multi';
        $arr['routes'] = [
            ['path' => '/',        'file' => 'index.html'],
            ['path' => '/about',   'file' => 'about.html'],
            ['path' => '/pricing', 'file' => 'pricing.html'],
        ];
        $manifest = Manifest::validate($arr);
        $html  = '<a href="/">Home</a>'
               . '<a href="/about">About</a>'
               . '<a href="/pricing">Pricing</a>'
               . '<a href="/wp-admin/post.php">WP admin</a>'   // explicit WP path — leave alone
               . '<a href="/contact">External</a>'             // not a route or bundle file — leave alone
               . '<a href="https://example.com">External</a>'  // absolute URL — leave alone
               . '<a href="#section">Hash</a>';                // fragment — leave alone
        $out = InlineRenderer::rewrite_bundle_asset_paths($html, $bundle, $manifest);
        $prefix = InlineRenderer::url_prefix_for($manifest);
        $this->assertStringContainsString('href="' . $prefix . '/"', $out);
        $this->assertStringContainsString('href="' . $prefix . '/about"', $out);
        $this->assertStringContainsString('href="' . $prefix . '/pricing"', $out);
        // Untouched paths
        $this->assertStringContainsString('href="/wp-admin/post.php"', $out);
        $this->assertStringContainsString('href="/contact"', $out);
        $this->assertStringContainsString('href="https://example.com"', $out);
        $this->assertStringContainsString('href="#section"', $out);
    }

    public function test_render_route_stamps_nonce_on_inline_scripts(): void {
        // Astro / Next inline modules — must be nonce-stamped so CSP allows them.
        $bundle = sys_get_temp_dir() . '/dsgo-stamp-' . uniqid();
        mkdir($bundle, 0755, true);
        file_put_contents($bundle . '/index.html',
            '<!doctype html><html><head></head><body>'
            . '<script type="module">console.log(1)</script>'
            . '<script>console.log(2)</script>'
            . '</body></html>');
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $output = InlineRenderer::render_route($bundle, $manifest, $manifest->routes[0], [], 'STAMP-N');
        // Both inline tags must carry the nonce.
        preg_match_all('#<script\b[^>]*>console\.log#', $output, $tags);
        $this->assertCount(2, $tags[0]);
        foreach ($tags[0] as $t) {
            $this->assertStringContainsString('nonce="STAMP-N"', $t, $t);
        }
    }

    public function test_generate_nonce_returns_unique_value(): void {
        $a = InlineRenderer::generate_nonce();
        $b = InlineRenderer::generate_nonce();
        $this->assertNotSame($a, $b);
        $this->assertGreaterThanOrEqual(16, strlen($a));
    }

    public function test_extract_body_content_returns_inner_body(): void {
        $extracted = InlineRenderer::extract_body_content(
            '<!doctype html><html><head><title>Hi</title></head><body><main>app body</main></body></html>',
        );
        $this->assertStringContainsString('<main>app body</main>', $extracted);
        $this->assertStringNotContainsString('<title>', $extracted);
        $this->assertStringNotContainsString('<body>', $extracted);
    }

    public function test_extract_body_content_passthroughs_when_no_body(): void {
        $extracted = InlineRenderer::extract_body_content('<main>fragment</main>');
        $this->assertSame('<main>fragment</main>', $extracted);
    }

    public function test_url_prefix_for_default(): void {
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $this->assertSame('/apps/sample-inline', InlineRenderer::url_prefix_for($manifest));
    }

    public function test_url_prefix_for_uses_configured_prefix(): void {
        update_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX, 'mini');
        try {
            $manifest = Manifest::validate($this->minimal_inline_manifest());
            $this->assertSame('/mini/sample-inline', InlineRenderer::url_prefix_for($manifest));
        } finally {
            delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
        }
    }

    public function test_url_prefix_for_root_mount_is_empty(): void {
        $arr = $this->minimal_inline_manifest();
        $arr['mount'] = ['mode' => 'root'];
        $manifest = Manifest::validate($arr);
        $this->assertSame('', InlineRenderer::url_prefix_for($manifest));
    }

    public function test_root_mount_skips_asset_path_rewrite(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-rootrewrite-' . uniqid();
        mkdir($bundle . '/_astro', 0755, true);
        file_put_contents($bundle . '/_astro/foo.js', '/* x */');
        $arr = $this->minimal_inline_manifest();
        $arr['mount'] = ['mode' => 'root'];
        $manifest = Manifest::validate($arr);
        $html = '<script src="/_astro/foo.js"></script>';
        $out = InlineRenderer::rewrite_bundle_asset_paths($html, $bundle, $manifest);
        // Root mount: site-absolute paths are already correct; nothing to splice.
        $this->assertSame($html, $out);
    }

    public function test_render_emits_ai_timeout_seconds_when_ai_permission_present(): void {
        $bundle_dir = sys_get_temp_dir() . '/dsgo-render-' . uniqid();
        mkdir($bundle_dir, 0755, true);
        file_put_contents($bundle_dir . '/index.html', '<!doctype html><html><body>x</body></html>');
        $arr = $this->minimal_inline_manifest();
        $arr['permissions']['read'][] = 'ai';
        $arr['ai'] = ['max_tool_calls' => 5, 'timeout_seconds' => 90];
        $manifest = \DSGo_Apps\Manifest::validate($arr);
        $route = $manifest->routes[0];
        $context = ['appId' => $manifest->id, 'mode' => 'inline', 'routePath' => '/',
                    'routeParams' => (object)[], 'locale' => 'en-US', 'theme' => 'light',
                    'aiTimeoutSeconds' => 90];
        $output = \DSGo_Apps\InlineRenderer::render_route($bundle_dir, $manifest, $route, $context, 'N');
        $this->assertMatchesRegularExpression('/"aiTimeoutSeconds":\s*90/', $output);
    }

    private function minimal_inline_manifest(): array {
        return [
            'manifest_version' => 1, 'id' => 'sample-inline', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ];
    }
}
