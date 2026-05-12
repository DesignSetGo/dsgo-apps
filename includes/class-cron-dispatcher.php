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
     * Fallback timeout in seconds, used when the manifest entry doesn't
     * carry a `timeout_seconds` value AND we can't read it from post meta
     * (e.g. malformed manifest array). Matches the manifest validator's
     * default in Manifest::validate_published_ability().
     */
    private const FALLBACK_TIMEOUT_SECONDS = 30;

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
        $app_post_id = $posts[0]->ID;

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

        // Step 3: budget the request. Read the published ability's
        // `timeout_seconds` from the stored manifest (the manifest
        // validator caps it at 5-120; falls back to 30 otherwise) and
        // raise the request's time limit to that + 10s headroom for the
        // log write. PHP-CLI cron runs without a hard ceiling, but fpm
        // workers need this so a slow ability doesn't trip max_execution.
        $timeout = self::resolve_timeout($app_post_id, $ability_name);
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- cron dispatch must extend the PHP timeout; default 30s would kill long-running app abilities
            set_time_limit($timeout + 10);
        }

        // Step 4: execute. The WP_Ability::execute wrapper already
        // catches Throwables from the callback and returns them as
        // WP_Error('ability_callback_exception'); that path is handled
        // in the is_wp_error branch below. The outer try/catch here is
        // defensive belt-and-suspenders for the rare case where
        // $ability->execute itself throws BEFORE the wrapper engages —
        // e.g. a malformed WP_Ability instance, a fatal during input
        // validation, or an Abilities-API bug. Cron must never surface
        // a fatal to wp-cron's process supervisor.
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
            // Three distinct WP_Error codes get distinct cron audit codes:
            //
            //   ability_callback_exception     → cron_exception
            //       (WP_Ability wrapped a Throwable from inside the callback)
            //   execute_php_class_not_loadable → cron_ability_not_found
            //       (AbilitiesPublisher's inactive-companion-plugin sentinel
            //        — operationally identical to a missing ability)
            //   default                        → cron_ability_execute_failed
            //       (ability deliberately returned WP_Error)
            $code = match ($result->get_error_code()) {
                'ability_callback_exception'     => 'cron_exception',
                'execute_php_class_not_loadable' => 'cron_ability_not_found',
                default                          => 'cron_ability_execute_failed',
            };
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

    /**
     * Resolve the published-ability `timeout_seconds` declared in the
     * manifest stored on the app's post. Returns FALLBACK_TIMEOUT_SECONDS
     * if the manifest can't be read or the ability entry is missing —
     * the dispatcher must always succeed in budgeting itself rather than
     * blocking dispatch on meta-read corner cases.
     */
    private static function resolve_timeout(int $app_post_id, string $ability_name): int {
        $manifest_arr = get_post_meta($app_post_id, 'dsgo_apps_manifest', true);
        if (!is_array($manifest_arr)) {
            return self::FALLBACK_TIMEOUT_SECONDS;
        }
        $publishes = $manifest_arr['abilities']['publishes'] ?? null;
        if (!is_array($publishes)) {
            return self::FALLBACK_TIMEOUT_SECONDS;
        }
        foreach ($publishes as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $ability_name && isset($entry['timeout_seconds']) && is_int($entry['timeout_seconds'])) {
                return $entry['timeout_seconds'];
            }
        }
        return self::FALLBACK_TIMEOUT_SECONDS;
    }
}
