<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CronDispatcher;
use DSGo_Apps\CronLog;
use DSGo_Apps\PostType;
use WP_UnitTestCase;

/**
 * Tests for Task 6 of the cron + webhooks plan: CronDispatcher executes a
 * scheduled ability and writes a single CronLog row per invocation.
 *
 * The dispatcher must NOT throw for any of the documented failure modes
 * (app not found, ability not registered, ability returns WP_Error,
 * ability throws an exception). Each failure path produces a row with
 * status='error' and a stable error_code so admins can spot patterns.
 */
final class CronDispatcherTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        CronLog::create_table();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_cron_log");
        PostType::register();
        // wp_register_ability requires the wp_abilities_api_init context.
        // Tests register inline; we wrap each register call in the filter
        // context so the ability is registered immediately, the same way
        // AbilitiesPublisher does.
    }

    public function test_run_app_not_found_logs_and_returns(): void {
        // No dsgo_app post with this slug exists.
        CronDispatcher::run('nonexistent', 'jobid', 'nonexistent/do-it');
        $rows = CronLog::query('nonexistent');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('cron_app_not_found', $rows[0]['error_code']);
        $this->assertSame('jobid', $rows[0]['job_id']);
    }

    public function test_run_ability_not_registered_logs_and_returns(): void {
        // App exists but the ability is not registered (companion plugin absent).
        $this->insert_app_post('myapp');
        CronDispatcher::run('myapp', 'sync', 'myapp/sync');
        $rows = CronLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('cron_ability_not_found', $rows[0]['error_code']);
    }

    public function test_run_executes_ability_and_writes_ok_log(): void {
        $this->insert_app_post('myapp');
        $this->register_test_ability('myapp/sync', static fn ($input = null) => ['synced' => true]);
        CronDispatcher::run('myapp', 'sync', 'myapp/sync');
        $rows = CronLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertNull($rows[0]['error_code']);
        $this->assertSame('myapp/sync', $rows[0]['ability_name']);
        $this->assertGreaterThanOrEqual(0, (int) $rows[0]['duration_ms']);
    }

    public function test_run_wp_error_from_execute_logs_error(): void {
        $this->insert_app_post('myapp');
        $this->register_test_ability('myapp/cleanup', static fn ($input = null) => new \WP_Error('cleanup_failed', 'Could not reach upstream service'));
        CronDispatcher::run('myapp', 'nightly', 'myapp/cleanup');
        $rows = CronLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('cron_ability_execute_failed', $rows[0]['error_code']);
        $this->assertStringContainsString('Could not reach upstream', (string) $rows[0]['error_msg']);
    }

    public function test_run_throwable_from_execute_logs_exception(): void {
        $this->insert_app_post('myapp');
        $this->register_test_ability('myapp/explode', static function ($input = null): array {
            throw new \RuntimeException('boom');
        });
        CronDispatcher::run('myapp', 'detonate', 'myapp/explode');
        $rows = CronLog::query('myapp');
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('cron_exception', $rows[0]['error_code']);
        $this->assertStringContainsString('boom', (string) $rows[0]['error_msg']);
    }

    public function test_run_fires_action_hook_on_success(): void {
        $fired = null;
        add_action(
            'dsgo_apps_cron_job_run',
            static function ($app_id, $job_id, $ability_name, $result, $duration) use (&$fired): void {
                $fired = compact('app_id', 'job_id', 'ability_name', 'result', 'duration');
            },
            10,
            5,
        );
        $this->insert_app_post('myapp');
        $this->register_test_ability('myapp/ping', static fn ($input = null) => ['pong' => 1]);
        CronDispatcher::run('myapp', 'health', 'myapp/ping');
        $this->assertNotNull($fired);
        $this->assertSame('myapp', $fired['app_id']);
        $this->assertSame('health', $fired['job_id']);
        $this->assertSame('myapp/ping', $fired['ability_name']);
        $this->assertSame(['pong' => 1], $fired['result']);
    }

    public function test_run_fires_failed_action_on_wp_error(): void {
        $fired = null;
        add_action(
            'dsgo_apps_cron_job_failed',
            static function ($app_id, $job_id, $ability_name, $error, $duration) use (&$fired): void {
                $fired = compact('app_id', 'job_id', 'ability_name', 'error');
            },
            10,
            5,
        );
        $this->insert_app_post('myapp');
        $this->register_test_ability('myapp/broken', static fn ($input = null) => new \WP_Error('whoops', 'busted'));
        CronDispatcher::run('myapp', 'sync', 'myapp/broken');
        $this->assertNotNull($fired);
        $this->assertInstanceOf(\WP_Error::class, $fired['error']);
        $this->assertSame('whoops', $fired['error']->get_error_code());
    }

    private function insert_app_post(string $app_id): int {
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $app_id,
            'post_title'  => $app_id,
        ]);
        $this->assertIsInt($post_id);
        return $post_id;
    }

    /**
     * Register a real WP_Ability with the given callback. Wraps the call
     * in the `wp_abilities_api_init` filter context that wp_register_ability
     * requires, mirroring how AbilitiesPublisher registers in production.
     */
    private function register_test_ability(string $name, callable $execute): void {
        global $wp_current_filter;
        // Categories must exist before abilities reference them.
        if (function_exists('wp_register_ability_category') && function_exists('wp_has_ability_category')) {
            $wp_current_filter[] = 'wp_abilities_api_categories_init';
            try {
                if (!wp_has_ability_category('content')) {
                    wp_register_ability_category('content', ['label' => 'Content', 'description' => 'Test category.']);
                }
            } finally {
                array_pop($wp_current_filter);
            }
        }
        $wp_current_filter[] = 'wp_abilities_api_init';
        try {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
            wp_register_ability($name, [
                'label'               => 'Test ability',
                'description'         => 'A test ability for the cron dispatcher tests.',
                'category'            => 'content',
                'permission_callback' => '__return_true',
                'execute_callback'    => $execute,
            ]);
        } finally {
            array_pop($wp_current_filter);
        }
    }
}
