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

$post_ids = $wpdb->get_col(
    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'dsgo_app')
);
foreach ($post_ids as $id) {
    wp_delete_post((int) $id, true);
}

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
        $wpdb->esc_like('dsgo_apps_storage_user_') . '%',
        $wpdb->esc_like('dsgo_apps_storage_size_user_') . '%'
    )
);


$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('dsgo_apps_') . '%'
    )
);

$upload   = wp_upload_dir();
$apps_dir = trailingslashit($upload['basedir']) . 'dsgo-apps';
if (is_dir($apps_dir)) {
    require_once __DIR__ . '/includes/class-bundle.php';
    \DSGo_Apps\Bundle::recursive_delete($apps_dir);
}
