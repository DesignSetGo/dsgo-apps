<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CronScheduler;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

/**
 * Tests for Task 4 of the cron + webhooks plan: the WP-cron-facing reconcile
 * layer. CronScheduler.reconcile() compares the previous manifest's
 * scheduled.jobs against the new manifest's, registers new jobs with
 * `wp_schedule_event`, unschedules removed jobs with `wp_clear_scheduled_hook`,
 * and leaves unchanged jobs alone so we don't reset their next-fire timer on
 * every plugin save.
 *
 * Sister tests (manifest-validation-side) live in ManifestScheduledTest.
 */
final class CronSchedulerTest extends WP_UnitTestCase {

    protected function tearDown(): void {
        // Clear any hooks the tests scheduled so subsequent tests don't
        // inherit cron state. wp_clear_scheduled_hook is idempotent.
        foreach (['daily-digest', 'old-job', 'hourly-sync', 'job-a', 'job-b'] as $job_id) {
            wp_clear_scheduled_hook("dsgo_apps_cron_myapp_$job_id");
        }
        parent::tearDown();
    }

    public function test_custom_schedules_registered(): void {
        $schedules = apply_filters('cron_schedules', []);
        $this->assertArrayHasKey('dsgo-15min', $schedules);
        $this->assertArrayHasKey('dsgo-5min', $schedules);
        $this->assertSame(900, $schedules['dsgo-15min']['interval']);
        $this->assertSame(300, $schedules['dsgo-5min']['interval']);
    }

    public function test_reconcile_schedules_new_job(): void {
        $manifest = $this->manifest_with_jobs('myapp', [
            ['id' => 'daily-digest', 'ability' => 'myapp/do-it', 'schedule' => 'daily', 'time' => '06:00'],
        ]);
        CronScheduler::reconcile('myapp', $manifest, null);
        $this->assertIsInt(wp_next_scheduled('dsgo_apps_cron_myapp_daily-digest'));
    }

    public function test_reconcile_unschedules_removed_job(): void {
        $prev = $this->manifest_with_jobs('myapp', [
            ['id' => 'old-job', 'ability' => 'myapp/do-it', 'schedule' => 'hourly'],
        ]);
        $new = $this->manifest_with_jobs('myapp', []);
        // Pre-schedule manually to simulate a previously installed job.
        wp_schedule_event(time(), 'hourly', 'dsgo_apps_cron_myapp_old-job');
        CronScheduler::reconcile('myapp', $new, $prev);
        $this->assertFalse(wp_next_scheduled('dsgo_apps_cron_myapp_old-job'));
    }

    public function test_reconcile_leaves_unchanged_job_schedule_intact(): void {
        $manifest = $this->manifest_with_jobs('myapp', [
            ['id' => 'hourly-sync', 'ability' => 'myapp/do-it', 'schedule' => 'hourly'],
        ]);
        CronScheduler::reconcile('myapp', $manifest, null);
        $first_run = wp_next_scheduled('dsgo_apps_cron_myapp_hourly-sync');
        $this->assertIsInt($first_run);
        // Reconcile with an unchanged manifest — schedule should be untouched.
        CronScheduler::reconcile('myapp', $manifest, $manifest);
        $this->assertSame($first_run, wp_next_scheduled('dsgo_apps_cron_myapp_hourly-sync'));
    }

    public function test_reconcile_reschedules_changed_job(): void {
        $prev = $this->manifest_with_jobs('myapp', [
            ['id' => 'daily-digest', 'ability' => 'myapp/do-it', 'schedule' => 'daily', 'time' => '06:00'],
        ]);
        $new = $this->manifest_with_jobs('myapp', [
            ['id' => 'daily-digest', 'ability' => 'myapp/do-it', 'schedule' => 'daily', 'time' => '09:00'],
        ]);
        // Pre-seed the previous schedule manually.
        wp_schedule_event(CronScheduler::next_run_time($prev->scheduled_jobs()[0]), 'daily', 'dsgo_apps_cron_myapp_daily-digest');
        $original = wp_next_scheduled('dsgo_apps_cron_myapp_daily-digest');
        CronScheduler::reconcile('myapp', $new, $prev);
        $rescheduled = wp_next_scheduled('dsgo_apps_cron_myapp_daily-digest');
        $this->assertIsInt($rescheduled);
        $this->assertNotSame($original, $rescheduled);
    }

    public function test_next_run_time_daily_with_time(): void {
        $job = ['schedule' => 'daily', 'time' => '06:00'];
        $ts = CronScheduler::next_run_time($job);
        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual(time(), $ts);
        $this->assertLessThanOrEqual(time() + DAY_IN_SECONDS, $ts);
    }

    public function test_next_run_time_weekly_with_time_and_day(): void {
        $job = ['schedule' => 'weekly', 'time' => '06:00', 'day_of_week' => 1];
        $ts = CronScheduler::next_run_time($job);
        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual(time(), $ts);
        // Weekly windows can be up to 7 days out.
        $this->assertLessThanOrEqual(time() + 7 * DAY_IN_SECONDS, $ts);
        // Verify the timestamp actually falls on Monday in the site's TZ.
        $tz = wp_timezone();
        $dt = (new \DateTimeImmutable("@$ts"))->setTimezone($tz);
        $this->assertSame('1', $dt->format('w'));
        $this->assertSame('06:00', $dt->format('H:i'));
    }

    public function test_next_run_time_bare_hourly(): void {
        // No `time` declared — falls back to "fire soon" (within a minute).
        $job = ['schedule' => 'hourly'];
        $ts = CronScheduler::next_run_time($job);
        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual(time(), $ts);
        $this->assertLessThanOrEqual(time() + 120, $ts);
    }

    public function test_unschedule_all_clears_all_jobs(): void {
        wp_schedule_event(time() + 60, 'hourly', 'dsgo_apps_cron_myapp_job-a');
        wp_schedule_event(time() + 60, 'hourly', 'dsgo_apps_cron_myapp_job-b');
        $this->assertIsInt(wp_next_scheduled('dsgo_apps_cron_myapp_job-a'));
        $this->assertIsInt(wp_next_scheduled('dsgo_apps_cron_myapp_job-b'));
        CronScheduler::unschedule_all('myapp', ['job-a', 'job-b']);
        $this->assertFalse(wp_next_scheduled('dsgo_apps_cron_myapp_job-a'));
        $this->assertFalse(wp_next_scheduled('dsgo_apps_cron_myapp_job-b'));
    }

    public function test_hook_format(): void {
        $this->assertSame('dsgo_apps_cron_myapp_my-job', CronScheduler::hook('myapp', 'my-job'));
    }

    /**
     * Build a validated Manifest with permissions.run=["scheduled"], one
     * published php-callable ability per cron job, and the supplied
     * scheduled.jobs[] array.
     *
     * @param array<int, array<string, mixed>> $jobs
     */
    private function manifest_with_jobs(string $app_id, array $jobs): Manifest {
        $arr = [
            'manifest_version' => 1,
            'id'               => $app_id,
            'name'             => 'Test App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => [], 'run' => ['scheduled']],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => [[
                'name'        => "$app_id/do-it",
                'label'       => 'Do it',
                'description' => 'A sample php-callable ability for cron tests.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ]]],
            'scheduled' => ['jobs' => $jobs],
        ];
        if ($jobs === []) {
            // permissions.run is still ["scheduled"], but there are no jobs —
            // validate() accepts an empty scheduled.jobs[] array.
            $arr['scheduled'] = ['jobs' => []];
        }
        return Manifest::validate($arr);
    }
}
