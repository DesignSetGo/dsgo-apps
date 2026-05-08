<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use WP_UnitTestCase;

class BlockRenderTest extends WP_UnitTestCase {

    private string $render_file;

    public function set_up(): void {
        parent::set_up();
        $this->render_file = dirname(__DIR__, 2) . '/block/src/render.php';
        $this->assertFileExists($this->render_file);
    }

    private function render(array $attributes): string {
        ob_start();
        include $this->render_file;
        return (string) ob_get_clean();
    }

    public function test_renders_placeholder_when_app_id_is_empty(): void {
        $output = $this->render(['appId' => '', 'height' => 480, 'autoResize' => false]);
        $this->assertStringContainsString('No app selected.', $output);
        $this->assertStringNotContainsString('<iframe', $output);
    }

    public function test_renders_placeholder_when_app_does_not_exist(): void {
        $output = $this->render(['appId' => 'never-installed', 'height' => 480, 'autoResize' => false]);
        $this->assertStringContainsString('not installed', $output);
        $this->assertStringNotContainsString('<iframe', $output);
    }

    public function test_renders_iframe_for_installed_block_capable_app(): void {
        $this->install_app('e2e-block', ['page', 'block']);

        $output = $this->render([
            'appId' => 'e2e-block', 'height' => 600, 'autoResize' => false,
        ]);

        // Block embeds now render directly into the parent post: an iframe
        // pointing at the bundle URL plus a JSON config island the
        // multi-iframe parent-bridge reads. No `?dsgo_embed=` query — the
        // outer WP-bootstrapped iframe-loader is gone.
        $this->assertStringContainsString('<iframe', $output);
        $this->assertStringContainsString('sandbox="allow-scripts"', $output);
        $this->assertStringContainsString('/wp-content/uploads/dsgo-apps/e2e-block/', $output);
        $this->assertStringContainsString('data-dsgo-embed-id="', $output);
        $this->assertStringContainsString('data-dsgo-app-id="e2e-block"', $output);
        $this->assertStringContainsString('data-dsgo-embed-config="', $output);
        $this->assertStringContainsString('height:600px', $output);
        $this->assertStringNotContainsString('dsgo_embed=', $output);
    }

    public function test_renders_placeholder_when_app_does_not_support_block_mode(): void {
        $this->install_app('page-only', ['page']);

        $output = $this->render([
            'appId' => 'page-only', 'height' => 480, 'autoResize' => false,
        ]);
        $this->assertStringContainsString('does not support', $output);
        $this->assertStringNotContainsString('<iframe', $output);
    }

    public function test_clamps_height_to_range(): void {
        $this->install_app('e2e-clamp', ['page', 'block']);

        $low = $this->render(['appId' => 'e2e-clamp', 'height' => 50, 'autoResize' => false]);
        $high = $this->render(['appId' => 'e2e-clamp', 'height' => 99999, 'autoResize' => false]);

        $this->assertStringContainsString('height:100px', $low);
        $this->assertStringContainsString('height:2000px', $high);
    }

    public function test_emits_auto_resize_in_config_island(): void {
        $this->install_app('e2e-ar', ['page', 'block']);

        $output = $this->render(['appId' => 'e2e-ar', 'height' => 480, 'autoResize' => true]);
        // autoResize moves into the per-embed config island (parent-bridge
        // reads it from there), not a data attribute on the iframe.
        $this->assertStringContainsString('"autoResize":true', $output);
        $this->assertStringContainsString('data-dsgo-embed-config="', $output);
    }

    public function test_align_class_is_emitted(): void {
        $this->install_app('e2e-align', ['page', 'block']);

        $output = $this->render([
            'appId' => 'e2e-align', 'height' => 480, 'autoResize' => false, 'align' => 'wide',
        ]);
        $this->assertStringContainsString('alignwide', $output);
    }

    private function install_app(string $id, array $modes): void {
        $post_id = wp_insert_post([
            'post_type'   => \DSGo_Apps\PostType::SLUG,
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
}
