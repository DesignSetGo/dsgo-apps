<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Manifest;
use WP_UnitTestCase;

/**
 * Tests for Task 2 of the cron + webhooks plan: `permissions.run` and
 * `scheduled.jobs[]` manifest validation. Each test exercises one error
 * code or one accepted shape; cross-reference rules (ability must exist
 * in abilities.publishes[] AND carry execute_php) live here too because
 * they're a property of scheduled-job validation, not the publish entry.
 *
 * Webhook-side validation lives in `ManifestWebhooksValidationTest`.
 * Shape-only validation for `execute_php` itself lives in
 * `ManifestCronWebhooksTest`.
 */
final class ManifestScheduledTest extends WP_UnitTestCase {

    // ===== permissions.run =====

    public function test_permissions_run_scheduled_alone_valid(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['scheduled'];
        $manifest = Manifest::validate($arr);
        $this->assertSame(['scheduled'], $manifest->raw_field('permissions.run'));
    }

    public function test_permissions_run_webhooks_alone_valid(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['webhooks'];
        $manifest = Manifest::validate($arr);
        $this->assertSame(['webhooks'], $manifest->raw_field('permissions.run'));
    }

    public function test_permissions_run_both_valid(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['scheduled', 'webhooks'];
        Manifest::validate($arr);
        $this->addToAssertionCount(1);
    }

    public function test_permissions_run_must_be_array(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = 'scheduled';
        $this->expectExceptionMessage('run_invalid');
        Manifest::validate($arr);
    }

    public function test_permissions_run_unknown_value_rejected(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['scheduled', 'cron'];
        $this->expectExceptionMessage('run_permission_unknown');
        Manifest::validate($arr);
    }

    public function test_permissions_run_duplicate_rejected(): void {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['scheduled', 'scheduled'];
        $this->expectExceptionMessage('run_permission_duplicate');
        Manifest::validate($arr);
    }

    // ===== scheduled.jobs =====

    public function test_scheduled_block_requires_run_permission(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'nightly', 'ability' => 'sample/do-it', 'schedule' => 'daily', 'time' => '03:00'],
        ]);
        unset($arr['permissions']['run']);
        $this->expectExceptionMessage('run_scheduled_not_permitted');
        Manifest::validate($arr);
    }

    public function test_scheduled_jobs_max_5_at_6_rejected(): void {
        $jobs = [];
        for ($i = 1; $i <= 6; $i++) {
            $jobs[] = ['id' => "job-$i", 'ability' => 'sample/do-it', 'schedule' => 'hourly'];
        }
        $arr = $this->scheduled_manifest($jobs);
        $this->expectExceptionMessage('scheduled_too_many');
        Manifest::validate($arr);
    }

    public function test_scheduled_jobs_5_accepted(): void {
        $jobs = [];
        for ($i = 1; $i <= 5; $i++) {
            $jobs[] = ['id' => "job-$i", 'ability' => 'sample/do-it', 'schedule' => 'hourly'];
        }
        $manifest = Manifest::validate($this->scheduled_manifest($jobs));
        $this->assertCount(5, $manifest->scheduled_jobs());
    }

    public function test_scheduled_duplicate_id_rejected(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'hourly'],
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'daily', 'time' => '04:00'],
        ]);
        $this->expectExceptionMessage('scheduled_duplicate_id');
        Manifest::validate($arr);
    }

    public function test_scheduled_id_regex_enforced(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'Bad Id!', 'ability' => 'sample/do-it', 'schedule' => 'hourly'],
        ]);
        $this->expectExceptionMessage('scheduled_id_invalid');
        Manifest::validate($arr);
    }

    public function test_scheduled_ability_not_found(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/missing', 'schedule' => 'hourly'],
        ]);
        $this->expectExceptionMessage('scheduled_ability_not_found');
        Manifest::validate($arr);
    }

    public function test_scheduled_ability_without_execute_php_rejected(): void {
        // Build a manifest with TWO published abilities; only one carries
        // execute_php. The schedule references the one WITHOUT execute_php,
        // so it must be rejected with scheduled_ability_not_php_callable.
        $arr = $this->base_manifest();
        $arr['abilities']['publishes'] = [
            // No execute_php — pure publish-only declaration.
            [
                'name'        => 'sample/do-it',
                'label'       => 'Do it',
                'description' => 'A sample published ability without php.',
                'category'    => 'content',
            ],
            // With execute_php — included so the manifest isn't empty on this
            // side; the test asserts the FIRST ability (without php) is the
            // one that fails the cross-ref.
            [
                'name'        => 'sample/with-php',
                'label'       => 'With php',
                'description' => 'A second ability carrying execute_php.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ],
        ];
        $arr['permissions']['run'] = ['scheduled'];
        $arr['scheduled'] = ['jobs' => [
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'hourly'],
        ]];
        $this->expectExceptionMessage('scheduled_ability_not_php_callable');
        Manifest::validate($arr);
    }

    /** @dataProvider valid_schedule_values */
    public function test_scheduled_valid_schedule_values(string $schedule): void {
        $job = ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => $schedule];
        if ($schedule === 'daily' || $schedule === 'weekly') {
            $job['time'] = '03:00';
        }
        if ($schedule === 'weekly') {
            $job['day_of_week'] = 1;
        }
        $manifest = Manifest::validate($this->scheduled_manifest([$job]));
        $this->assertSame($schedule, $manifest->scheduled_jobs()[0]['schedule']);
    }

    /** @return array<string, array{0:string}> */
    public static function valid_schedule_values(): array {
        return [
            'hourly'     => ['hourly'],
            'twicedaily' => ['twicedaily'],
            'daily'      => ['daily'],
            'weekly'     => ['weekly'],
            'dsgo-15min' => ['dsgo-15min'],
            'dsgo-5min'  => ['dsgo-5min'],
        ];
    }

    public function test_scheduled_invalid_schedule_rejected(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'every-minute'],
        ]);
        $this->expectExceptionMessage('scheduled_invalid_schedule');
        Manifest::validate($arr);
    }

    public function test_scheduled_time_on_hourly_rejected(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'hourly', 'time' => '06:00'],
        ]);
        $this->expectExceptionMessage('scheduled_time_not_applicable');
        Manifest::validate($arr);
    }

    /** @dataProvider invalid_time_strings */
    public function test_scheduled_invalid_time_rejected(string $time): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'daily', 'time' => $time],
        ]);
        $this->expectExceptionMessage('scheduled_invalid_time');
        Manifest::validate($arr);
    }

    /** @return array<string, array{0:string}> */
    public static function invalid_time_strings(): array {
        return [
            'hour out of range'   => ['25:00'],
            'minute out of range' => ['06:60'],
            'one digit hour'      => ['6:00'],
            'no colon'            => ['0600'],
            'empty'               => [''],
        ];
    }

    public function test_scheduled_day_of_week_on_daily_rejected(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'daily', 'time' => '03:00', 'day_of_week' => 1],
        ]);
        $this->expectExceptionMessage('scheduled_day_not_applicable');
        Manifest::validate($arr);
    }

    public function test_scheduled_day_of_week_out_of_range_rejected(): void {
        $arr = $this->scheduled_manifest([
            ['id' => 'sync', 'ability' => 'sample/do-it', 'schedule' => 'weekly', 'time' => '03:00', 'day_of_week' => 7],
        ]);
        $this->expectExceptionMessage('scheduled_invalid_day');
        Manifest::validate($arr);
    }

    public function test_scheduled_jobs_typed_accessor_round_trip(): void {
        $jobs = [
            ['id' => 'nightly',     'ability' => 'sample/do-it', 'schedule' => 'daily', 'time' => '03:00'],
            ['id' => 'hourly-sync', 'ability' => 'sample/do-it', 'schedule' => 'hourly'],
        ];
        $manifest = Manifest::validate($this->scheduled_manifest($jobs));
        $this->assertSame($jobs, $manifest->scheduled_jobs());
    }

    // ===== helpers =====

    /** @return array<string, mixed> */
    private function base_manifest(): array {
        return [
            'manifest_version' => 1,
            'id'               => 'sample',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function valid_manifest_with_php_ability(): array {
        $arr = $this->base_manifest();
        $arr['abilities']['publishes'][] = [
            'name'        => 'sample/do-it',
            'label'       => 'Do it',
            'description' => 'A sample published ability with php callback.',
            'category'    => 'content',
            'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
        ];
        return $arr;
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array<string, mixed>
     */
    private function scheduled_manifest(array $jobs): array {
        $arr = $this->valid_manifest_with_php_ability();
        $arr['permissions']['run'] = ['scheduled'];
        $arr['scheduled'] = ['jobs' => $jobs];
        return $arr;
    }
}
