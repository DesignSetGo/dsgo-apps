<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\ElementorWidget;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

/**
 * Covers ElementorWidget::block_apps() in isolation. The actual Widget_Base
 * subclass in class-elementor-app-widget.php is not exercised here because
 * it requires Elementor's own autoload to resolve the parent class — that
 * path is covered manually in a wp-env site with Elementor installed (see
 * the manual QA checklist in the PR description).
 */
class ElementorWidgetTest extends WP_UnitTestCase {

    public function test_block_apps_includes_iframe_apps(): void {
        $this->install_app('only-page',   ['page'],            'Page Only');
        $this->install_app('only-block',  ['block'],           'Block Only');
        $this->install_app('both-modes',  ['page', 'block'],   'Both Modes');

        $apps = ElementorWidget::block_apps();
        $ids  = array_map(static fn (array $a): string => $a['id'], $apps);

        $this->assertContains('only-page', $ids);
        $this->assertContains('only-block', $ids);
        $this->assertContains('both-modes', $ids);
    }

    public function test_block_apps_label_includes_version(): void {
        $this->install_app('versioned', ['block'], 'Versioned App', '2.3.1');
        $apps = ElementorWidget::block_apps();

        $match = null;
        foreach ($apps as $a) {
            if ($a['id'] === 'versioned') {
                $match = $a;
                break;
            }
        }
        $this->assertNotNull($match);
        $this->assertStringContainsString('Versioned App', $match['label']);
        $this->assertStringContainsString('v2.3.1', $match['label']);
    }

    public function test_block_apps_skips_apps_without_manifest(): void {
        // Bare post with no manifest meta — block_apps must not surface it.
        wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'manifest-less',
            'post_title'  => 'Manifest-less',
        ]);
        $this->install_app('with-manifest', ['block'], 'With Manifest');

        $ids = array_map(static fn (array $a): string => $a['id'], ElementorWidget::block_apps());
        $this->assertContains('with-manifest', $ids);
        $this->assertNotContains('manifest-less', $ids);
    }

    public function test_block_apps_returns_empty_array_on_clean_site(): void {
        $this->assertSame([], ElementorWidget::block_apps());
    }

    /**
     * @param array<int, string> $modes
     */
    private function install_app(string $id, array $modes, string $title, string $version = '0.1.0'): void {
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $id,
            'post_title'  => $title,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => $title,
            'version'          => $version,
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => $modes, 'default' => $modes[0]],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]);
    }
}
