<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CronLog;
use DSGo_Apps\PostType;
use DSGo_Apps\RestApi;
use DSGo_Apps\Secret_Vault;
use DSGo_Apps\WebhookLog;
use DSGo_Apps\WebhookQueue;
use WP_UnitTestCase;

/**
 * Tests for Task 14b of the cron+webhooks plan: the two admin-ajax
 * handlers powering the cron + webhooks tab interactivity.
 *
 *   - dsgo_apps_cron_run_now      → fire CronDispatcher::run inline,
 *                                    return the newly-written log row.
 *   - dsgo_apps_webhook_send_test → server-side sign a body, dispatch
 *                                    it through WebhookHandler, return
 *                                    the response status + body.
 *
 * Both gate on manage_options + a per-app nonce.
 */
final class AdminCronWebhooksTest extends WP_UnitTestCase {

    private const APP_ID      = 'admin-ajax-app';
    private const ENDPOINT_ID = 'stripe-events';
    private const ALIAS       = 'STRIPE_TEST_SECRET';
    private const SECRET      = 'whsec_admin_ajax_test_aaaaaaaaaaaaaaaaaa';

    public function set_up(): void {
        parent::set_up();
        CronLog::create_table();
        WebhookLog::create_table();
        WebhookQueue::create_table();
        PostType::register();
        // The plugin's `init` listener that binds the wp_ajax_* hooks
        // already ran once at boot, but the test runtime can drift
        // (other tests remove filters, init re-fires, etc.). Bind the
        // handlers explicitly so do_action('wp_ajax_...') always finds
        // the listener.
        RestApi::register_admin_ajax();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_cron_log");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_webhook_log");
        Secret_Vault::set(self::APP_ID, self::ALIAS, self::SECRET);
    }

    public function tear_down(): void {
        Secret_Vault::delete_all(self::APP_ID);
        unset($_POST['action'], $_POST['app_id'], $_POST['job_id'], $_POST['endpoint_id'], $_POST['body'], $_POST['nonce']);
        parent::tear_down();
    }

    // ===== nonce + auth gating =====

    public function test_admin_ajax_handlers_are_registered(): void {
        // Sanity check — verify the wp_ajax_* listeners are bound
        // before the rest of the test class assumes they exist.
        $this->assertNotFalse(has_action('wp_ajax_dsgo_apps_cron_run_now'));
        $this->assertNotFalse(has_action('wp_ajax_dsgo_apps_webhook_send_test'));
    }

    public function test_cron_run_now_requires_manage_options(): void {
        $this->install_app_with_job('sync', 'admin-ajax-app/handle', fn () => ['ok' => true]);
        wp_set_current_user($this->factory->user->create(['role' => 'subscriber']));
        $_POST['action'] = 'dsgo_apps_cron_run_now';
        $_POST['app_id'] = self::APP_ID;
        $_POST['job_id'] = 'sync';
        $_POST['nonce']  = RestApi::cron_webhooks_nonce(self::APP_ID);
        $_REQUEST        = $_POST;

        $response = $this->invoke_handler_directly([RestApi::class, 'ajax_cron_run_now']);
        $this->assertFalse($response['success']);
        $this->assertSame('forbidden', $response['data']['code']);
        $this->assertCount(0, CronLog::query(self::APP_ID));
    }

    public function test_cron_run_now_fires_dispatcher_and_returns_log_row(): void {
        $this->install_app_with_job('sync', 'admin-ajax-app/handle', fn () => ['done' => true]);
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        $_POST['action'] = 'dsgo_apps_cron_run_now';
        $_POST['app_id'] = self::APP_ID;
        $_POST['job_id'] = 'sync';
        $_POST['nonce']  = RestApi::cron_webhooks_nonce(self::APP_ID);
        $_REQUEST        = $_POST;

        // Call the handler directly — bypassing do_action lets the
        // test stay agnostic to wp_die routing and the DOING_AJAX
        // constant, which can drift across phpunit's process lifetime.
        $response = $this->invoke_handler_directly([RestApi::class, 'ajax_cron_run_now']);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('log', $response['data']);
        $this->assertSame('ok', $response['data']['log']['status']);

        $rows = CronLog::query(self::APP_ID);
        $this->assertCount(1, $rows);
        $this->assertSame('sync', $rows[0]['job_id']);
    }

    public function test_cron_run_now_rejects_unknown_job_id(): void {
        $this->install_app_with_job('sync', 'admin-ajax-app/handle', fn () => ['ok' => true]);
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        $_POST['action'] = 'dsgo_apps_cron_run_now';
        $_POST['app_id'] = self::APP_ID;
        $_POST['job_id'] = 'does-not-exist';
        $_POST['nonce']  = RestApi::cron_webhooks_nonce(self::APP_ID);
        $_REQUEST        = $_POST;

        $response = $this->invoke_handler_directly([RestApi::class, 'ajax_cron_run_now']);
        $this->assertFalse($response['success']);
        $this->assertSame('job_not_found', $response['data']['code']);
    }

    public function test_webhook_send_test_signs_payload_and_returns_200(): void {
        $captured = null;
        $this->install_app_with_endpoint(self::ENDPOINT_ID, 'admin-ajax-app/handle', function ($input) use (&$captured) {
            $captured = $input;
            return ['ok' => true];
        });
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        $_POST['action']      = 'dsgo_apps_webhook_send_test';
        $_POST['app_id']      = self::APP_ID;
        $_POST['endpoint_id'] = self::ENDPOINT_ID;
        $_POST['body']        = '{"event":"test"}';
        $_POST['nonce']       = RestApi::cron_webhooks_nonce(self::APP_ID);
        $_REQUEST             = $_POST;

        $response = $this->invoke_handler_directly([RestApi::class, 'ajax_webhook_send_test']);
        $this->assertTrue($response['success']);
        $this->assertSame(200, $response['data']['status']);
        $this->assertTrue($response['data']['ok']);
        $this->assertNotNull($captured, 'ability must have received the dispatched payload');
        $this->assertSame(['event' => 'test'], $captured['body']);
    }

    public function test_webhook_send_test_returns_503_when_secret_missing(): void {
        $this->install_app_with_endpoint(self::ENDPOINT_ID, 'admin-ajax-app/handle', fn () => ['ok' => true]);
        Secret_Vault::delete_all(self::APP_ID);
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        $_POST['action']      = 'dsgo_apps_webhook_send_test';
        $_POST['app_id']      = self::APP_ID;
        $_POST['endpoint_id'] = self::ENDPOINT_ID;
        $_POST['body']        = '{"event":"test"}';
        $_POST['nonce']       = RestApi::cron_webhooks_nonce(self::APP_ID);
        $_REQUEST             = $_POST;

        $response = $this->invoke_handler_directly([RestApi::class, 'ajax_webhook_send_test']);
        $this->assertFalse($response['success']);
        $this->assertSame('webhook_secret_not_set', $response['data']['code']);
    }

    // ===== helpers =====

    /**
     * @param callable(mixed):mixed $execute
     */
    private function install_app_with_job(string $job_id, string $ability_name, callable $execute): void {
        // Cron-dispatch path calls $ability->execute(null), so register
        // the ability WITHOUT an input_schema — a schema would reject
        // null input and the dispatcher would log 'cron_ability_execute_failed'
        // instead of running our test callback.
        $this->install_manifest_and_ability($ability_name, $execute, [
            'permissions' => ['read' => [], 'write' => [], 'run' => ['scheduled']],
            'scheduled'   => ['jobs' => [
                ['id' => $job_id, 'ability' => $ability_name, 'schedule' => 'hourly'],
            ]],
        ], /* with_input_schema */ false);
    }

    /**
     * @param callable(mixed):mixed $execute
     */
    private function install_app_with_endpoint(string $endpoint_id, string $ability_name, callable $execute): void {
        // Webhook-dispatch path passes a {body, raw, headers, method}
        // array to the ability, so the schema (object with arbitrary
        // properties) is appropriate here.
        $this->install_manifest_and_ability($ability_name, $execute, [
            'permissions' => ['read' => [], 'write' => [], 'run' => ['webhooks']],
            'secrets'     => [['alias' => self::ALIAS, 'description' => 'Stripe signing secret (test).']],
            'webhooks'    => ['endpoints' => [[
                'id'      => $endpoint_id,
                'ability' => $ability_name,
                'auth'    => ['type' => 'hmac-sha256', 'scheme' => 'stripe', 'secret_alias' => self::ALIAS],
            ]]],
        ], /* with_input_schema */ true);
    }

    /**
     * Insert the post + manifest meta + register an ability so the
     * dispatch path resolves end-to-end.
     *
     * @param callable(mixed):mixed $execute
     * @param array<string, mixed>  $extra Additional manifest top-level fields.
     */
    private function install_manifest_and_ability(string $ability_name, callable $execute, array $extra, bool $with_input_schema = true): void {
        $manifest_arr = array_merge([
            'manifest_version' => 1,
            'id'               => self::APP_ID,
            'name'             => self::APP_ID,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => [[
                'name'        => $ability_name,
                'label'       => 'Handle',
                'description' => 'Test handler.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ]]],
        ], $extra);
        $post_id = wp_insert_post([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => self::APP_ID,
            'post_title'  => self::APP_ID,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest_arr);

        global $wp_current_filter;
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
            if (function_exists('wp_has_ability') && wp_has_ability($ability_name)) {
                wp_unregister_ability($ability_name);
            }
            $args = [
                'label'               => 'Test handler',
                'description'         => 'Test handler.',
                'category'            => 'content',
                'permission_callback' => '__return_true',
                'execute_callback'    => $execute,
            ];
            if ($with_input_schema) {
                $args['input_schema'] = ['type' => 'object', 'additionalProperties' => true];
            }
            wp_register_ability($ability_name, $args);
        } finally {
            array_pop($wp_current_filter);
        }
    }

    /**
     * Call an admin-ajax handler directly (skipping do_action) and
     * decode the JSON wp_send_json_* writes. Keeps the capture tight
     * to a single function call so the buffer math is simple.
     *
     * @param callable $handler
     * @return array<string, mixed>
     */
    private function invoke_handler_directly(callable $handler): array {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $die_handler = static function (): void {
            throw new \WPAjaxDieContinueException('');
        };
        add_filter('wp_die_ajax_handler', static fn () => $die_handler);
        add_filter('wp_die_handler',      static fn () => $die_handler);

        $body = '';
        ob_start();
        try {
            $handler();
        } catch (\WPAjaxDieContinueException | \WPAjaxDieStopException $e) {
            // expected
        } finally {
            $body = (string) ob_get_clean();
        }
        remove_all_filters('wp_die_ajax_handler');
        remove_all_filters('wp_die_handler');
        $payload = json_decode($body, true);
        $this->assertIsArray($payload, 'expected JSON payload, got: ' . var_export($body, true));
        return $payload;
    }

    /**
     * Fire an admin-ajax action through the registered listener and
     * decode the JSON wp_send_json_* writes before wp_die() fires.
     *
     * The trick: wp_send_json calls echo + wp_die. If wp_die is allowed
     * to terminate the process the test dies. We swap in a die handler
     * that grabs the buffered body and throws an exception we can catch.
     * Both wp_die_handler (non-ajax) and wp_die_ajax_handler (ajax)
     * routes are intercepted so the capture works regardless of how
     * wp_send_json routes — wp_doing_ajax() can be flaky during phpunit
     * because the constant DOING_AJAX is per-process and other tests
     * may have already raced.
     *
     * @return array<string, mixed>
     */
    private function capture_ajax_json(string $action): array {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $captured = '';
        $die_handler = static function () use (&$captured): void {
            // ob_get_clean may have already been called (different
            // wp_die path); guard with ob_get_level so we don't pop
            // a buffer that doesn't exist.
            if (ob_get_level() > 0) {
                $captured = (string) ob_get_clean();
            }
            throw new \WPAjaxDieContinueException('');
        };
        add_filter('wp_die_ajax_handler',   static fn () => $die_handler);
        add_filter('wp_die_handler',        static fn () => $die_handler);

        $start_lvl = ob_get_level();
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (\WPAjaxDieContinueException | \WPAjaxDieStopException $e) {
            // body captured by die handler above (or below)
        }
        // Drain anything still buffered (echoed-but-not-die'd output).
        while (ob_get_level() > $start_lvl) {
            $chunk = (string) ob_get_clean();
            if ($captured === '' && $chunk !== '') {
                $captured = $chunk;
            }
        }
        remove_all_filters('wp_die_ajax_handler');
        remove_all_filters('wp_die_handler');
        $payload = json_decode($captured, true);
        $this->assertIsArray($payload, 'expected JSON payload, got: ' . var_export($captured, true));
        return $payload;
    }
}
