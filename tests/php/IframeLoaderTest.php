<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\IframeLoader;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

class IframeLoaderTest extends WP_UnitTestCase {

    public function test_render_outputs_host_page(): void {
        $post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'sample',
            'post_title'  => 'Sample',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1,
            'id' => 'sample', 'name' => 'Sample', 'version' => '0.1.0',
            'entry' => 'index.html',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['posts'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => ['https://api.openai.com']],
        ]);

        ob_start();
        IframeLoader::render('sample');
        $html = ob_get_clean();

        // Page-mode iframe-loader emits the same multi-iframe shape blocks
        // use: a JSON config island the parent-bridge reads, not legacy
        // `window.__dsgo*` globals.
        $this->assertStringContainsString('data-dsgo-embed-config="1"', $html);
        $this->assertStringContainsString('data-dsgo-embed-id="1"', $html);
        $this->assertStringContainsString('"appId":"sample"', str_replace(' ', '', $html));
        $this->assertStringContainsString('parent-bridge.js', $html);
        $this->assertStringContainsString('sandbox="allow-scripts allow-forms allow-top-navigation-by-user-activation"', $html);
        $this->assertStringContainsString('api-fetch', $html);
        $this->assertStringContainsString('wpApiSettings', $html);
    }

    public function test_render_omits_iframe_csp_attribute(): void {
        $post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'csp-omit',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1,
            'id' => 'csp-omit', 'name' => 'X', 'version' => '0.1.0',
            'entry' => 'index.html',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ]);

        ob_start();
        IframeLoader::render('csp-omit');
        $html = ob_get_clean();

        $this->assertDoesNotMatchRegularExpression('#<iframe[^>]*\bcsp=#i', $html);
    }

    // ── Task 4: render_block_placeholder + can_render_for_block helpers ──

    public function test_render_block_placeholder_emits_inert_div_with_height(): void {
        $html = IframeLoader::render_block_placeholder('Some reason.', 600, 'alignwide');
        $this->assertStringContainsString('Some reason.', $html);
        $this->assertStringContainsString('min-height:600px', $html);
        $this->assertStringContainsString('alignwide', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_render_block_placeholder_escapes_reason(): void {
        $html = IframeLoader::render_block_placeholder('<script>alert(1)</script>', 480, '');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_can_render_for_block_returns_true_for_block_capable_app(): void {
        $this->install('app-block', ['page', 'block']);
        $this->assertSame(true, IframeLoader::can_render_for_block('app-block'));
    }

    public function test_can_render_for_block_returns_string_for_missing_app(): void {
        $msg = IframeLoader::can_render_for_block('never-installed');
        $this->assertIsString($msg);
        $this->assertStringContainsString('not installed', $msg);
    }

    public function test_can_render_for_block_accepts_page_only_iframe_app(): void {
        $this->install('app-page', ['page']);
        $this->assertSame(true, IframeLoader::can_render_for_block('app-page'));
    }

    public function test_render_includes_ai_timeout_in_embed_config_when_ai_permission_present(): void {
        $post_id = $this->factory->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'ai-app',
            'post_title'  => 'AI App',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1, 'id' => 'ai-app', 'name' => 'AI App',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['ai'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'ai' => ['max_tool_calls' => 5, 'timeout_seconds' => 90],
        ]);

        ob_start();
        \DSGo_Apps\IframeLoader::render('ai-app');
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression('/"aiTimeoutSeconds":\s*90/', $html);
    }

    public function test_render_omits_ai_timeout_when_ai_permission_absent(): void {
        $post_id = $this->factory->post->create([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'plain-app',
            'post_title'  => 'Plain App',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1, 'id' => 'plain-app', 'name' => 'Plain App',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ]);

        ob_start();
        \DSGo_Apps\IframeLoader::render('plain-app');
        $html = ob_get_clean();

        $this->assertStringNotContainsString('aiTimeoutSeconds', $html);
    }

    private function install(string $id, array $modes): void {
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $id,
            'post_title'  => ucfirst($id),
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => ucfirst($id),
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => $modes, 'default' => $modes[0]],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]);
    }

    /**
     * Seed an iframe-mode root app so dispatch_root has work to find.
     * Mirrors what the /site-home REST handler writes after a promote.
     */
    private function install_iframe_root(string $id): int {
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $id,
            'post_title'  => ucfirst($id),
        ]);
        $manifest = [
            'manifest_version' => 1, 'id' => $id, 'name' => ucfirst($id),
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'mount' => ['mode' => 'root'],
        ];
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
        update_post_meta($post_id, 'dsgo_apps_mount_mode', 'root');
        update_post_meta($post_id, 'dsgo_apps_isolation', 'iframe');
        \DSGo_Apps\Settings::refresh_root_app_id();
        return (int) $post_id;
    }

    public function test_dispatch_root_falls_through_for_non_root_path(): void {
        $this->install_iframe_root('home-app');
        $_SERVER['REQUEST_URI'] = '/some-other-path';

        ob_start();
        \DSGo_Apps\IframeLoader::maybe_dispatch_root();
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_dispatch_root_falls_through_when_no_root_app(): void {
        // No root app installed — dispatcher must be a noop regardless of path.
        delete_option('dsgo_apps_root_app_id');
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        \DSGo_Apps\IframeLoader::maybe_dispatch_root();
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_dispatch_root_falls_through_for_inline_app(): void {
        // The iframe dispatcher must not hijack inline-mode root apps;
        // the inline renderer's root dispatcher (priority 7) handles those.
        $post_id = wp_insert_post([
            'post_type' => PostType::SLUG, 'post_status' => 'publish',
            'post_name' => 'inline-home', 'post_title' => 'Inline Home',
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1, 'id' => 'inline-home', 'name' => 'Inline',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
            'mount' => ['mode' => 'root'],
        ]);
        update_post_meta($post_id, 'dsgo_apps_mount_mode', 'root');
        update_post_meta($post_id, 'dsgo_apps_isolation', 'inline');
        \DSGo_Apps\Settings::refresh_root_app_id();
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        \DSGo_Apps\IframeLoader::maybe_dispatch_root();
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }
}
