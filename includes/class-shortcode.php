<?php
/**
 * `[dsgo_app]` shortcode — renders a block-mode DSGo app embed inside any
 * surface that runs WP shortcodes (Classic Editor, Elementor's Shortcode
 * widget, Divi, Beaver Builder, Bricks text blocks, theme template tags
 * via `do_shortcode()`, etc.).
 *
 * Delegates to {@see IframeLoader::render_block_embed()} so the markup,
 * permissions, and bridge wiring stay identical to the Gutenberg block.
 *
 * Usage:
 *     [dsgo_app id="my-app"]
 *     [dsgo_app id="my-app" height="640" auto_resize="1" align="wide"]
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Shortcode {

    public const TAG = 'dsgo_app';

    public static function register(): void {
        add_shortcode(self::TAG, [self::class, 'render']);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function render($atts): string {
        $atts = shortcode_atts(
            [
                'id'          => '',
                'height'      => '480',
                'auto_resize' => '0',
                'align'       => '',
            ],
            is_array($atts) ? $atts : [],
            self::TAG,
        );

        $app_id      = sanitize_key((string) $atts['id']);
        $height      = max(100, min(2000, (int) $atts['height']));
        $auto_resize = filter_var($atts['auto_resize'], FILTER_VALIDATE_BOOLEAN);
        // Mirror the Gutenberg block's render path (block/build/render.php):
        // any non-empty string gets `align`-prefixed and sanitize_html_class'd.
        // Stricter validation here would diverge from the block in ways that
        // bite people copy-pasting between surfaces.
        $align_raw   = (string) $atts['align'];
        $align_class = $align_raw !== '' ? 'align' . sanitize_html_class($align_raw) : '';

        if ($app_id === '') {
            return IframeLoader::render_block_placeholder(
                __('No app selected.', 'designsetgo-apps'),
                $height,
                $align_class,
            );
        }

        return IframeLoader::render_block_embed($app_id, $height, $auto_resize, $align_class);
    }
}
