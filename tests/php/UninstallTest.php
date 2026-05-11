<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use WP_UnitTestCase;

class UninstallTest extends WP_UnitTestCase {

    public function test_uninstall_removes_apps_meta_options_cron_and_disk(): void {
        $post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'doomed',
        ]);
        update_post_meta($post_id, 'dsgo_apps_storage_app_x', '"v"');
        update_post_meta($post_id, 'dsgo_apps_storage_size_app', '3');

        $uid = $this->factory->user->create();
        update_user_meta($uid, "dsgo_apps_storage_user_{$post_id}_pref", '42');
        update_user_meta($uid, "dsgo_apps_storage_size_user_{$post_id}", '2');

        // Plural-prefix and singular-prefix options must both be removed —
        // the inline renderer's cache version uses the singular `dsgo_app_`
        // form which an earlier LIKE pattern missed.
        update_option('dsgo_apps_root_app_id', 'doomed');
        update_option('dsgo_apps_url_prefix', 'apps');
        update_option('dsgo_app_cache_version_doomed', 'v1-uuid');
        set_transient('dsgo_email_rate_doomed_2026050816', 5, HOUR_IN_SECONDS);
        set_transient('dsgo_ai_rate_doomed_2026050816', 7, HOUR_IN_SECONDS);

        // Bundle dir on disk must also be cleaned up.
        $bundle_dir = wp_upload_dir()['basedir'] . '/designsetgo-apps/doomed';
        wp_mkdir_p($bundle_dir);
        file_put_contents($bundle_dir . '/index.html', '<!doctype html>');
        $this->assertTrue(is_dir($bundle_dir));

        // Pending cron events must be cleared.
        wp_schedule_single_event(time() + 600, 'dsgo_apps_cleanup_user_storage', [$post_id]);
        $this->assertNotFalse(wp_next_scheduled('dsgo_apps_cleanup_user_storage', [$post_id]));

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__, 2) . '/uninstall.php';

        global $wpdb;
        $this->assertNull(get_post($post_id));
        $this->assertSame('', get_user_meta($uid, "dsgo_apps_storage_user_{$post_id}_pref", true));
        $this->assertFalse(get_option('dsgo_apps_root_app_id', false));
        $this->assertFalse(get_option('dsgo_apps_url_prefix', false));
        $this->assertFalse(get_option('dsgo_app_cache_version_doomed', false));

        // Transient _options_ rows must be gone (the object-cache copy may
        // outlive the request when a persistent cache is configured; we test
        // the durable storage layer rather than the cache layer).
        $this->assertSame('0', $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_dsgo_email_rate_') . '%',
                $wpdb->esc_like('_transient_dsgo_ai_rate_') . '%',
            ),
        ));

        $this->assertFalse(is_dir($bundle_dir));
        $this->assertFalse(wp_next_scheduled('dsgo_apps_cleanup_user_storage', [$post_id]));
    }
}
