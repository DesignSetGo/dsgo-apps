<?php
/**
 * Elementor widget for embedding a DesignSetGo App.
 *
 * Loaded only when Elementor is active (the loader bottom of this file is
 * the only public surface that hooks into Elementor; without Elementor's
 * autoload running, `\Elementor\Widget_Base` doesn't exist and the widget
 * class itself never resolves).
 *
 * Renders through {@see IframeLoader::render_block_embed()} so the
 * sandbox flags, bridge wiring, and CSP posture stay identical to the
 * Gutenberg block and the `[dsgo_app]` shortcode.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class ElementorWidget {

    /**
     * Hook the registration onto Elementor's widget-register action. Elementor
     * only fires this when its own bootstrap has loaded, so PHP never tries
     * to resolve the Widget_Base parent class on sites without Elementor.
     */
    public static function register(): void {
        add_action('elementor/widgets/register', [self::class, 'register_widget']);
    }

    public static function register_widget(\Elementor\Widgets_Manager $widgets_manager): void {
        require_once __DIR__ . '/class-elementor-app-widget.php';
        $widgets_manager->register(new ElementorAppWidget());
    }

    /**
     * Return installed iframe apps that can render as embeds. Used by the
     * widget's app picker; also reusable for any future Elementor controls
     * (e.g. a route-specific dropdown).
     *
     * Only invoked while the Elementor editor panel is rendering its
     * controls — never on a public page render — so the unbounded
     * `posts_per_page => -1` + per-app `get_post_meta` scan is acceptable
     * at install counts the lite plugin supports today.
     *
     * TODO: when an installation routinely exceeds ~100 apps, replace the
     * scan with a `dsgo_apps_block_modes` transient primed by
     * Installer::install / RestApi::delete_app, mirroring the same
     * pattern used by the cron-dispatch boot path
     * (Plugin::register_cron_dispatch_hooks, class-plugin.php:262).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function block_apps(): array {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) continue;
            $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
            if (!is_array($manifest)) continue;
            if (!in_array('block', Manifest::display_modes_for_runtime($manifest), true)) continue;

            $version = isset($manifest['version']) ? (string) $manifest['version'] : '';
            $label   = $post->post_title;
            if ($version !== '') {
                $label .= ' (v' . $version . ')';
            }
            $out[] = [
                'id'    => $post->post_name,
                'label' => $label,
            ];
        }
        return $out;
    }
}
