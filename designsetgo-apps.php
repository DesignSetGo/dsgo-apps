<?php
/**
 * Plugin Name:       DesignSetGo Apps
 * Plugin URI:        https://designsetgo.com/apps
 * Description:       Drop in any Claude artifact or AI-built static bundle and run it as a sandboxed app on your WordPress site at /apps/{slug}, wired to your posts, pages, and users via a permissioned bridge. Lite includes 1 active app per site; Pro removes the cap and adds in-admin AI generation + CLI deploy.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      8.2
 * Author:            DesignSetGo
 * Author URI:        https://designsetgo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dsgo-apps
 * Domain Path:       /languages
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80200) {
    add_action('admin_notices', static function (): void {
        $msg = sprintf(
            /* translators: %s: current PHP version */
            __('DesignSetGo Apps requires PHP 8.2 or higher. You are running %s.', 'dsgo-apps'),
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    });
    return;
}

global $wp_version;
if (version_compare((string) $wp_version, '6.9', '<')) {
    add_action('admin_notices', static function (): void {
        global $wp_version;
        $msg = sprintf(
            /* translators: %s: current WordPress version */
            __('DesignSetGo Apps requires WordPress 6.9 or higher. You are running %s.', 'dsgo-apps'),
            (string) $wp_version
        );
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    });
    return;
}

define('DSGO_APPS_VERSION', '0.2.0');
define('DSGO_APPS_FILE', __FILE__);
define('DSGO_APPS_PATH', plugin_dir_path(__FILE__));
define('DSGO_APPS_URL', plugin_dir_url(__FILE__));

require_once DSGO_APPS_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', static function (): void {
    \DSGo_Apps\Plugin::get_instance();
});

register_activation_hook(__FILE__, [\DSGo_Apps\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\DSGo_Apps\Plugin::class, 'deactivate']);
