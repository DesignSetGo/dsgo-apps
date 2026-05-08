<?php
/**
 * DesignSetGo Apps — uninstall cleanup.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Clear any pending cron events the plugin may have queued (e.g. batched
// usermeta cleanup) so they don't sit in wp_cron pointing at handlers that
// no longer exist. wp_unschedule_hook clears all events for the hook
// regardless of arguments — wp_clear_scheduled_hook with no args only
// matches events that were scheduled with no args.
wp_unschedule_hook('dsgo_apps_cleanup_user_storage');

// Uninstall runs once and must enumerate every dsgo_app post and every
// per-user storage row regardless of object cache state. WP's high-level APIs
// don't expose meta_key prefix matching across users.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dsgo_post_ids = $wpdb->get_col(
    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'dsgo_app')
);
foreach ($dsgo_post_ids as $id) {
    wp_delete_post((int) $id, true);
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
        $wpdb->esc_like('dsgo_apps_storage_user_') . '%',
        $wpdb->esc_like('dsgo_apps_storage_size_user_') . '%'
    )
);

// Drop the well-known options through delete_option so the alloptions cache
// stays coherent. Anything else with the plugin's prefix is swept by the
// LIKE-based catch-all below.
delete_option('dsgo_apps_root_app_id');
delete_option('dsgo_apps_url_prefix');
delete_option('dsgo_apps_harness_share_content');
delete_option('dsgo_apps_activation_notice');

// Cover both prefixes: most options use `dsgo_apps_` (plural) but the inline
// renderer's per-app cache version uses `dsgo_app_cache_version_` (singular).
// Also remove the rate-limit transients (both halves: value + timeout) so
// they don't linger across a reinstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like('dsgo_apps_') . '%',
        $wpdb->esc_like('dsgo_app_cache_version_') . '%',
        $wpdb->esc_like('_transient_dsgo_email_rate_') . '%',
        $wpdb->esc_like('_transient_timeout_dsgo_email_rate_') . '%',
        $wpdb->esc_like('_transient_dsgo_ai_rate_') . '%',
        $wpdb->esc_like('_transient_timeout_dsgo_ai_rate_') . '%'
    )
);

// The bulk DELETE bypasses delete_option's cache invalidation; flush the
// object-cache groups that may now hold stale values.
wp_cache_delete('alloptions', 'options');
wp_cache_delete('notoptions', 'options');

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dsgo_upload = wp_upload_dir();
$apps_dir = trailingslashit($dsgo_upload['basedir']) . 'dsgo-apps';
if (is_dir($apps_dir)) {
    require_once __DIR__ . '/includes/class-bundle.php';
    \DSGo_Apps\Bundle::recursive_delete($apps_dir);
}
