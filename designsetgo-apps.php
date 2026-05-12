<?php
/**
 * Plugin Name:       DesignSetGo Apps
 * Plugin URI:        https://designsetgo.dev
 * Description:       Drop in any AI-built static bundle and run it as a sandboxed app on your WordPress site at /apps/{slug}, wired to your posts, pages, and users via a permissioned bridge.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Tested up to:      6.9
 * Requires PHP:      8.2
 * Author:            DesignSetGo
 * Author URI:        https://designsetgo.dev/author/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       designsetgo-apps
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
            __('DesignSetGo Apps requires PHP 8.2 or higher. You are running %s.', 'designsetgo-apps'),
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
            __('DesignSetGo Apps requires WordPress 6.9 or higher. You are running %s.', 'designsetgo-apps'),
            (string) $wp_version
        );
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    });
    return;
}

define('DSGO_APPS_VERSION', '1.0.0');
define('DSGO_APPS_FILE', __FILE__);
define('DSGO_APPS_PATH', plugin_dir_path(__FILE__));
define('DSGO_APPS_URL', plugin_dir_url(__FILE__));

// Freemius SDK — embedded in the free build so unlicensed users get an
// in-admin Pricing page and Freemius can auto-install the Pro plugin on
// successful checkout. Registered against the same product ID Pro uses.
//
// Skipped entirely when the Pro plugin is active: Pro and Lite share
// product id 29375, and the Freemius SDK keys its singleton by product
// id. Whichever build calls fs_dynamic_init first wins — Lite (loaded
// alphabetically before Pro) would otherwise initialize the singleton
// with is_premium=false and Pro's later re-initialization wouldn't flip
// the flag, leaving the SDK believing the free build is active. With
// this guard, Lite's SDK only fires in Lite-only installs (the only
// situation where it's needed for the in-admin Pricing menu); Pro's
// own SDK init takes over the moment Pro is activated.
//
// Loaded by including the SDK's start.php directly rather than going
// through vendor/autoload.php. The SDK ships with a composer `files`
// autoload entry, but composer's autoload fires at PHP process start
// (PHPUnit's binary loads it before WP boots) — at which point start.php
// hits its `! defined('ABSPATH') return;` guard and never defines
// fs_dynamic_init. Loading start.php explicitly from the plugin file
// ensures it runs at plugin-load time, when ABSPATH and the rest of WP
// are present. `include` is used (not require_once) so the SDK runs
// even if composer's autoload already touched it during the early-load
// path. The SDK's own definitions are guarded with !function_exists /
// !class_exists, so re-running start.php is idempotent.
$dsgo_pro_active = in_array(
    'designsetgo-apps-pro/designsetgo-apps-pro.php',
    (array) get_option('active_plugins', []),
    true,
);
$dsgo_lite_fs_sdk = DSGO_APPS_PATH . 'vendor/freemius/wordpress-sdk/start.php';
if (!$dsgo_pro_active && is_readable($dsgo_lite_fs_sdk)) {
    include $dsgo_lite_fs_sdk;

    if (!function_exists('dsgo_apps_fs')) {
        function dsgo_apps_fs() {
            global $dsgo_apps_fs;

            if (!isset($dsgo_apps_fs)) {
                $dsgo_apps_fs = fs_dynamic_init([
                    'id'                  => '29375',
                    'slug'                => 'designsetgo-apps',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_5bf45bdaaee7135ffa177071473c2',
                    'is_premium'          => false,
                    'has_premium_version' => true,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'is_org_compliant'    => true,
                    'trial'               => [
                        'days'               => 14,
                        'is_require_payment' => false,
                    ],
                    'menu'                => [
                        'slug'    => 'designsetgo-apps',
                        'support' => false,
                    ],
                ]);
            }

            return $dsgo_apps_fs;
        }

        dsgo_apps_fs();
        do_action('dsgo_apps_fs_loaded');

        // Route Lite's pricing/upgrade CTAs (per-app "Activate Pro" badge,
        // CLI 402 response) at Freemius's in-admin checkout URL so users
        // never need to leave wp-admin to upgrade. Pro registers the same
        // filter later (plugins_loaded@20) and overrides this at the same
        // priority when both plugins are active — both URLs point at the
        // same Freemius product so the destination is equivalent.
        add_filter('dsgo_apps_pro_pricing_url', static function (string $default): string {
            if (function_exists('dsgo_apps_fs')) {
                $fs = dsgo_apps_fs();
                if (is_object($fs) && method_exists($fs, 'checkout_url')) {
                    return (string) $fs->checkout_url(WP_FS__PERIOD_ANNUALLY, false);
                }
            }
            return $default;
        });
        add_filter('dsgo_apps_pro_trial_url', static function (string $default): string {
            if (function_exists('dsgo_apps_fs')) {
                $fs = dsgo_apps_fs();
                if (is_object($fs) && method_exists($fs, 'checkout_url')) {
                    return (string) $fs->checkout_url(WP_FS__PERIOD_ANNUALLY, true);
                }
            }
            return $default;
        });
        add_filter('dsgo_apps_pro_upgrade_url', static function (string $default): string {
            if (function_exists('dsgo_apps_fs')) {
                $fs = dsgo_apps_fs();
                if (is_object($fs) && method_exists($fs, 'checkout_url')) {
                    return (string) $fs->checkout_url(WP_FS__PERIOD_ANNUALLY, false);
                }
            }
            return $default;
        });
    }
} elseif (!$dsgo_pro_active) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
           . esc_html__('DesignSetGo Apps: Freemius SDK is missing (composer dependencies not installed). Apps will continue to run, but the in-admin Pricing/Upgrade page is unavailable.', 'designsetgo-apps')
           . '</p></div>';
    });
}

require_once DSGO_APPS_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', static function (): void {
    \DSGo_Apps\Plugin::get_instance();
});

register_activation_hook(__FILE__, [\DSGo_Apps\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\DSGo_Apps\Plugin::class, 'deactivate']);
