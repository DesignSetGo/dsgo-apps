<?php
/**
 * Reconciles WP-cron event registrations against a manifest's
 * `scheduled.jobs[]` block.
 *
 * Two-phase responsibility:
 *
 *   1. Register two custom intervals (`dsgo-15min`, `dsgo-5min`) on the
 *      `cron_schedules` filter so manifests can opt into faster cadences
 *      than WP-core offers.
 *
 *   2. On install / update / delete, diff the previous manifest's jobs
 *      against the new manifest's and call `wp_schedule_event` /
 *      `wp_clear_scheduled_hook` to bring the cron registry in line.
 *      Unchanged jobs are deliberately left intact — re-registering would
 *      reset their next-fire timer on every plugin save.
 *
 * Each job is bound to the hook name `dsgo_apps_cron_<app_id>_<job_id>`,
 * which the CronDispatcher (Task 6) hangs an action on at plugin boot.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class CronScheduler {

    /**
     * `cron_schedules` filter callback. Adds DSGo's two extra intervals to
     * whatever WP-core already provides. Intervals match the manifest enum
     * exposed in `Manifest::validate_scheduled()`.
     *
     * @param array<string, array{interval:int, display:string}> $schedules
     * @return array<string, array{interval:int, display:string}>
     */
    public static function register_custom_schedules(array $schedules): array {
        $schedules['dsgo-15min'] = [
            'interval' => 900,
            'display'  => __('Every 15 minutes (DesignSetGo Apps)', 'designsetgo-apps'),
        ];
        $schedules['dsgo-5min'] = [
            'interval' => 300,
            'display'  => __('Every 5 minutes (DesignSetGo Apps)', 'designsetgo-apps'),
        ];
        return $schedules;
    }

    /**
     * Bring the WP-cron registry into line with the manifest's
     * scheduled.jobs[] block. Called from Installer on install/update.
     *
     * Behavior:
     *   - Job in prev but not new → unscheduled.
     *   - Job in both, same schedule/time/day → left intact.
     *   - Job in both, schedule/time/day differs → cleared and re-scheduled.
     *   - Job in new but not prev → scheduled fresh.
     *
     * `$prev_manifest` is null on first install.
     */
    public static function reconcile(string $app_id, Manifest $manifest, ?Manifest $prev_manifest): void {
        $prev_jobs = $prev_manifest !== null ? $prev_manifest->scheduled_jobs() : [];
        $new_jobs  = $manifest->scheduled_jobs();

        // Keyed lookups by job id — the manifest validator already
        // guarantees uniqueness.
        $prev_by_id = [];
        foreach ($prev_jobs as $job) {
            $prev_by_id[$job['id']] = $job;
        }
        $new_by_id = [];
        foreach ($new_jobs as $job) {
            $new_by_id[$job['id']] = $job;
        }

        // Unschedule removed jobs.
        foreach ($prev_by_id as $id => $job) {
            if (!isset($new_by_id[$id])) {
                wp_clear_scheduled_hook(self::hook($app_id, $id));
            }
        }

        // Schedule new; reschedule changed; leave unchanged alone.
        foreach ($new_by_id as $id => $job) {
            $hook = self::hook($app_id, $id);
            if (isset($prev_by_id[$id])) {
                $prev = $prev_by_id[$id];
                $unchanged = ($prev['schedule'] ?? null)    === ($job['schedule'] ?? null)
                          && ($prev['time'] ?? null)        === ($job['time'] ?? null)
                          && ($prev['day_of_week'] ?? null) === ($job['day_of_week'] ?? null);
                if ($unchanged) {
                    continue;
                }
                wp_clear_scheduled_hook($hook);
            }
            wp_schedule_event(self::next_run_time($job), $job['schedule'], $hook);
        }
    }

    /**
     * Unschedule every job in `$job_ids` for `$app_id`. Used by the
     * REST API's app-delete handler so a deleted app doesn't leave
     * orphan cron rows pointing at a non-existent ability.
     *
     * @param string[] $job_ids
     */
    public static function unschedule_all(string $app_id, array $job_ids): void {
        foreach ($job_ids as $id) {
            wp_clear_scheduled_hook(self::hook($app_id, $id));
        }
    }

    /**
     * Compute the first-fire timestamp for a job.
     *
     * For `daily`/`weekly` jobs with a declared `time`, aligns to the next
     * future occurrence of that wall-clock time in the site-local timezone
     * (weekly additionally honors `day_of_week`).
     *
     * For schedules without an alignment hint (`hourly`, `twicedaily`,
     * `dsgo-15min`, `dsgo-5min`, or `daily`/`weekly` without `time`),
     * returns `time() + 60` — WP-cron will fire on its next tick.
     *
     * @param array<string, mixed> $job
     */
    public static function next_run_time(array $job): int {
        $schedule = $job['schedule'] ?? null;
        $time_str = $job['time'] ?? null;

        if (is_string($time_str)
            && is_string($schedule)
            && in_array($schedule, ['daily', 'weekly'], true)
            && preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time_str, $m) === 1
        ) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $tz = wp_timezone();
            $now = new \DateTimeImmutable('now', $tz);
            $candidate = $now->setTime($h, $i, 0);
            if ($candidate <= $now) {
                $candidate = $candidate->add(new \DateInterval('P1D'));
            }
            if ($schedule === 'weekly' && isset($job['day_of_week']) && is_int($job['day_of_week'])) {
                $target_dow  = $job['day_of_week'];
                $current_dow = (int) $candidate->format('w');
                $diff = ($target_dow - $current_dow + 7) % 7;
                if ($diff > 0) {
                    $candidate = $candidate->add(new \DateInterval("P{$diff}D"));
                }
            }
            return $candidate->getTimestamp();
        }

        return time() + 60;
    }

    /**
     * The WP-cron hook name for a (app, job) pair.
     */
    public static function hook(string $app_id, string $job_id): string {
        return "dsgo_apps_cron_{$app_id}_{$job_id}";
    }
}
