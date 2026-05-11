<?php
/**
 * Executes a scheduled ability when its WP-cron hook fires.
 *
 * Bound to `dsgo_apps_cron_<app_id>_<job_id>` actions registered on init by
 * Plugin::register_cron_dispatch_hooks(). The cron registry resolves which
 * (app, job, ability) tuple to invoke; this class actually runs the
 * ability, writes one CronLog row, and surfaces public action hooks for
 * extensibility:
 *
 *   - `dsgo_apps_cron_job_run`    (every invocation, even errors)
 *   - `dsgo_apps_cron_job_failed` (errors only, both WP_Error + exceptions)
 *
 * Failure modes the dispatcher must absorb without re-throwing:
 *
 *   - `cron_app_not_found`            the dsgo_app post was deleted
 *   - `cron_ability_not_found`        companion plugin absent / ability missing
 *   - `cron_ability_execute_failed`   ability returned WP_Error
 *   - `cron_exception`                ability threw an exception
 *
 * Every cron tick must produce exactly one log row regardless of outcome.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class CronDispatcher {

    /**
     * Maximum length of `error_msg` written to the log table. The column
     * is TEXT but we trim aggressively so a runaway message doesn't blow
     * out per-row storage. The error_code is the load-bearing field;
     * error_msg is supplemental.
     */
    private const ERROR_MSG_MAX = 1000;

    /**
     * Default per-job timeout in seconds when the ability doesn't carry
     * one. Mirrors the manifest validator's default in
     * Manifest::validate_published_ability().
     */
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Fire the ability scheduled for (app_id, job_id). Bound to
     * `dsgo_apps_cron_<app_id>_<job_id>` via add_action at boot. Never
     * throws — every failure path resolves to a CronLog row.
     */
    public static function run(string $app_id, string $job_id, string $ability_name): void {
        $start_ms = self::now_ms();

        // Step 1: confirm the app still exists. A scheduled hook can
        // survive its app being deleted if the delete handler doesn't
        // call CronScheduler::unschedule_all, so we re-check here.
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'name'           => $app_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        if ($posts === []) {
            self::log_error($app_id, $job_id, $ability_name, $start_ms, 'cron_app_not_found', 'App not found');
            return;
        }

        // Step 2: resolve the ability. The companion plugin may not be
        // installed yet (or may have been deactivated). Check existence
        // with wp_has_ability() FIRST — wp_get_ability() emits
        // `_doing_it_wrong` if the ability isn't registered, and we
        // don't want that noise on what is otherwise an expected and
        // recoverable state.
        if (!function_exists('wp_has_ability') || !function_exists('wp_get_ability')) {
            self::log_error($app_id, $job_id, $ability_name, $start_ms, 'cron_ability_not_found', 'Abilities API not available');
            return;
        }
        if (!wp_has_ability($ability_name)) {
            self::log_error($app_id, $job_id, $ability_name, $start_ms, 'cron_ability_not_found', 'Ability not registered');
            return;
        }
        $ability = wp_get_ability($ability_name);
        if (!$ability) {
            self::log_error($app_id, $job_id, $ability_name, $start_ms, 'cron_ability_not_found', 'Ability not registered');
            return;
        }

        // Step 3: budget the request. PHP-CLI cron runs without a hard
        // time-limit; long jobs in fpm should at least raise the ceiling.
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::DEFAULT_TIMEOUT_SECONDS + 10);
        }

        // Step 4: execute. Catch every Throwable so the cron tick never
        // surfaces a fatal to WP-cron's process supervisor.
        try {
            $result = $ability->execute(null);
        } catch (\Throwable $e) {
            $duration = self::now_ms() - $start_ms;
            self::log_error($app_id, $job_id, $ability_name, $start_ms, 'cron_exception', $e->getMessage(), $duration);
            do_action(
                'dsgo_apps_cron_job_failed',
                $app_id,
                $job_id,
                $ability_name,
                new \WP_Error('cron_exception', $e->getMessage()),
                $duration,
            );
            do_action('dsgo_apps_cron_job_run', $app_id, $job_id, $ability_name, null, $duration);
            return;
        }

        $duration = self::now_ms() - $start_ms;

        if (is_wp_error($result)) {
            // WP_Ability::execute() catches Throwables inside the callback and
            // wraps them in a WP_Error with code `ability_callback_exception`.
            // Surface that case as `cron_exception` so the audit log
            // distinguishes "ability crashed" from "ability returned WP_Error
            // deliberately" — the two have different operational meaning.
            $code = $result->get_error_code() === 'ability_callback_exception'
                ? 'cron_exception'
                : 'cron_ability_execute_failed';
            self::log_error($app_id, $job_id, $ability_name, $start_ms, $code, $result->get_error_message(), $duration);
            do_action('dsgo_apps_cron_job_failed', $app_id, $job_id, $ability_name, $result, $duration);
        } else {
            CronLog::insert([
                'app_id'       => $app_id,
                'job_id'       => $job_id,
                'ability_name' => $ability_name,
                'fired_at'     => current_time('mysql', true),
                'duration_ms'  => $duration,
                'status'       => 'ok',
                'error_code'   => null,
                'error_msg'    => null,
            ]);
        }

        do_action('dsgo_apps_cron_job_run', $app_id, $job_id, $ability_name, $result, $duration);
    }

    private static function log_error(string $app_id, string $job_id, string $ability_name, int $start_ms, string $code, string $message, ?int $duration = null): void {
        CronLog::insert([
            'app_id'       => $app_id,
            'job_id'       => $job_id,
            'ability_name' => $ability_name,
            'fired_at'     => current_time('mysql', true),
            'duration_ms'  => $duration ?? (self::now_ms() - $start_ms),
            'status'       => 'error',
            'error_code'   => $code,
            'error_msg'    => substr($message, 0, self::ERROR_MSG_MAX),
        ]);
    }

    private static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }
}
