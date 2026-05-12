<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Plugin;
use DSGo_Apps\CronScheduler;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

/**
 * The cron dispatch hooks installed by Plugin::register_cron_dispatch_hooks
 * are a Pro-gated feature. Free sites accept manifests with scheduled.jobs
 * but do not bind WP-Cron callbacks; Pro sites do.
 */
final class CronGateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Register the post type so wp_insert_post accepts 'dsgo_app'.
        PostType::register();
        // Simulate an admin context so register_cron_dispatch_hooks passes
        // its wp_doing_cron() / is_admin() early guard.
        set_current_screen('options');
        // Clear the per-request memoization so each test runs the full scan.
        wp_cache_delete('dispatch_hooks_bound', 'dsgo_apps_cron');
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        // Restore a front-end screen so other test classes aren't affected.
        set_current_screen('front');
        wp_cache_delete('dispatch_hooks_bound', 'dsgo_apps_cron');
        parent::tear_down();
    }

    public function test_cron_dispatch_hooks_are_not_bound_on_free_sites(): void {
        $this->install_test_app_with_scheduled_job('alpha-cron', 'daily-sync', 'alpha-cron.sync');
        remove_all_filters('dsgo_apps_pro_feature_enabled');

        Plugin::register_cron_dispatch_hooks();

        $hook = CronScheduler::hook('alpha-cron', 'daily-sync');
        $this->assertFalse(
            has_action($hook),
            'Cron dispatch hook should not be bound when ProFeatureGate is closed'
        );
    }

    public function test_cron_dispatch_hooks_are_bound_when_gate_is_open(): void {
        $this->install_test_app_with_scheduled_job('beta-cron', 'daily-sync', 'beta-cron.sync');
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $en, string $f): bool {
            return $f === 'cron' ? true : $en;
        }, 10, 2);

        Plugin::register_cron_dispatch_hooks();

        $hook = CronScheduler::hook('beta-cron', 'daily-sync');
        $this->assertNotFalse(
            has_action($hook),
            'Cron dispatch hook should be bound when ProFeatureGate is open'
        );
    }

    private function install_test_app_with_scheduled_job(string $slug, string $job_id, string $ability): void {
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $slug,
            'post_title'  => $slug,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', [
            'id'        => $slug,
            'scheduled' => ['jobs' => [['id' => $job_id, 'ability' => $ability, 'schedule' => 'daily']]],
        ]);
    }
}
