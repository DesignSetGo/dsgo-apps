<?php
/**
 * The actual Elementor widget class. Lives in its own file because
 * `\Elementor\Widget_Base` doesn't exist on sites without Elementor, and
 * autoloading this file unconditionally would fatal. ElementorWidget::register_widget()
 * is the only entry point and only runs inside Elementor's own action.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class ElementorAppWidget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'dsgo-app';
    }

    public function get_title(): string {
        return __('DesignSetGo App', 'designsetgo-apps');
    }

    public function get_icon(): string {
        return 'eicon-code';
    }

    /**
     * @return array<int, string>
     */
    public function get_categories(): array {
        return ['general'];
    }

    /**
     * @return array<int, string>
     */
    public function get_keywords(): array {
        return ['dsgo', 'designsetgo', 'app', 'embed'];
    }

    protected function register_controls(): void {
        $this->start_controls_section(
            'section_app',
            [
                'label' => __('App', 'designsetgo-apps'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ],
        );

        $apps = ElementorWidget::block_apps();

        if ($apps === []) {
            $this->add_control(
                'no_apps_notice',
                [
                    'type'            => \Elementor\Controls_Manager::RAW_HTML,
                    'raw'             => sprintf(
                        '<div class="elementor-panel-alert elementor-panel-alert-info">%s <a href="%s" target="_blank">%s</a></div>',
                        esc_html__('No embeddable iframe apps are installed yet.', 'designsetgo-apps'),
                        esc_url(admin_url('admin.php?page=designsetgo-apps')),
                        esc_html__('Install one →', 'designsetgo-apps'),
                    ),
                    'content_classes' => 'elementor-descriptor',
                ],
            );
        } else {
            $options = ['' => __('— Choose an app —', 'designsetgo-apps')];
            foreach ($apps as $entry) {
                $options[$entry['id']] = $entry['label'];
            }
            $this->add_control(
                'app_id',
                [
                    'label'       => __('App', 'designsetgo-apps'),
                    'type'        => \Elementor\Controls_Manager::SELECT,
                    'options'     => $options,
                    'default'     => '',
                    'description' => __('Iframe apps are available for page views and embeds.', 'designsetgo-apps'),
                ],
            );
        }

        $this->end_controls_section();

        $this->start_controls_section(
            'section_layout',
            [
                'label' => __('Layout', 'designsetgo-apps'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ],
        );

        $this->add_control(
            'height',
            [
                'label'      => __('Height (px)', 'designsetgo-apps'),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range'      => [
                    'px' => ['min' => 100, 'max' => 2000, 'step' => 10],
                ],
                'default'    => ['unit' => 'px', 'size' => 480],
                'condition'  => ['auto_resize!' => 'yes'],
            ],
        );

        $this->add_control(
            'auto_resize',
            [
                'label'        => __('Auto-resize to content', 'designsetgo-apps'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __('On', 'designsetgo-apps'),
                'label_off'    => __('Off', 'designsetgo-apps'),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => __('Lets the app set its own height. Requires the app to send a dsgo:resize message.', 'designsetgo-apps'),
            ],
        );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings    = $this->get_settings_for_display();
        $app_id      = isset($settings['app_id']) ? sanitize_key((string) $settings['app_id']) : '';
        $height      = isset($settings['height']['size']) ? (int) $settings['height']['size'] : 480;
        $height      = max(100, min(2000, $height));
        $auto_resize = ($settings['auto_resize'] ?? '') === 'yes';

        if ($app_id === '') {
            // Always render a visible placeholder (not just inside the editor
            // iframe) so a misconfigured widget on the published page surfaces
            // visibly instead of collapsing silently. Mirrors the Gutenberg
            // block's render path at designsetgo-apps/block/build/render.php.
            echo IframeLoader::render_block_placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeLoader emits sanitized HTML.
                __('No app selected.', 'designsetgo-apps'),
                $height,
                '',
            );
            return;
        }

        echo IframeLoader::render_block_embed($app_id, $height, $auto_resize, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IframeLoader emits sanitized iframe markup.
    }
}
