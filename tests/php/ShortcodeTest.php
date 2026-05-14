<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use DSGo_Apps\Shortcode;
use WP_UnitTestCase;

class ShortcodeTest extends WP_UnitTestCase {

    public function test_missing_id_renders_placeholder(): void {
        $html = do_shortcode('[' . Shortcode::TAG . ']');
        $this->assertStringContainsString('No app selected.', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_unknown_id_renders_not_installed_placeholder(): void {
        $html = do_shortcode('[' . Shortcode::TAG . ' id="never-installed"]');
        $this->assertStringContainsString('not installed', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_page_only_app_renders_does_not_support_placeholder(): void {
        $this->install('page-only', ['page']);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="page-only"]');
        $this->assertStringContainsString('does not support block embedding', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_block_app_renders_iframe_embed(): void {
        $this->install('embeddable', ['page', 'block']);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="embeddable"]');
        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('data-dsgo-app-id="embeddable"', $html);
        $this->assertStringContainsString('sandbox="allow-scripts allow-forms allow-top-navigation-by-user-activation"', $html);
    }

    public function test_height_is_clamped_to_min_and_max(): void {
        $this->install('clampy', ['block']);

        $low_html = do_shortcode('[' . Shortcode::TAG . ' id="clampy" height="10"]');
        $this->assertMatchesRegularExpression('/height:\s*100px/', $low_html);

        $high_html = do_shortcode('[' . Shortcode::TAG . ' id="clampy" height="99999"]');
        $this->assertMatchesRegularExpression('/height:\s*2000px/', $high_html);
    }

    /**
     * @dataProvider truthy_auto_resize
     */
    public function test_auto_resize_truthy_values_pass_through(string $value): void {
        $this->install('arx-' . md5($value), ['block']);
        $slug = 'arx-' . md5($value);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="' . $slug . '" auto_resize="' . $value . '"]');
        $this->assertMatchesRegularExpression('/"autoResize":\s*true/', $html);
    }

    /** @return array<string, array{string}> */
    public static function truthy_auto_resize(): array {
        return [
            '"1"'    => ['1'],
            '"yes"'  => ['yes'],
            '"true"' => ['true'],
            '"on"'   => ['on'],
        ];
    }

    /**
     * @dataProvider falsy_auto_resize
     */
    public function test_auto_resize_falsy_values_pass_through(string $value): void {
        $this->install('arf-' . md5($value), ['block']);
        $slug = 'arf-' . md5($value);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="' . $slug . '" auto_resize="' . $value . '"]');
        $this->assertMatchesRegularExpression('/"autoResize":\s*false/', $html);
    }

    /** @return array<string, array{string}> */
    public static function falsy_auto_resize(): array {
        return [
            'empty' => [''],
            '"0"'   => ['0'],
            '"no"'  => ['no'],
        ];
    }

    public function test_align_renders_wp_block_align_class(): void {
        $this->install('aligned', ['block']);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="aligned" align="wide"]');
        $this->assertStringContainsString('alignwide', $html);
    }

    public function test_unknown_align_passes_through_sanitize_html_class(): void {
        // Matches the Gutenberg block's render path — any sanitize_html_class-safe
        // string is accepted. Asserts the symmetry the reviewer flagged.
        $this->install('aligned2', ['block']);
        $html = do_shortcode('[' . Shortcode::TAG . ' id="aligned2" align="center"]');
        $this->assertStringContainsString('aligncenter', $html);
    }

    /**
     * @param array<int, string> $modes
     */
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
}
