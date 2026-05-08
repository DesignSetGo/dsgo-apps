<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use WP_UnitTestCase;

class UninstallTest extends WP_UnitTestCase {

    public function test_uninstall_removes_apps_and_meta(): void {
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

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertNull(get_post($post_id));
        $this->assertSame('', get_user_meta($uid, "dsgo_apps_storage_user_{$post_id}_pref", true));
    }
}
