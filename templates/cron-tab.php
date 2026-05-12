<?php
/**
 * Per-app Cron tab template. Rendered by AdminPage::render_cron_tab().
 *
 * Read-only v1: lists declared jobs and recent log rows. The "Run now"
 * button + JS interactivity ship in a follow-up commit (admin-ajax
 * handler for `dsgo_apps_cron_run_now`).
 *
 * $ctx:
 *   - app_id    string
 *   - app_name  string
 *   - jobs      array<int, array{id:string,ability:string,schedule:string,time?:string,day_of_week?:int}>
 *   - log_rows  array<int, array<string, mixed>>
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Template-scope locals (passed in by AdminPage::render_cron_tab), not plugin globals.

/** @var array{
 *   app_id:string,
 *   app_name:string,
 *   jobs:array<int, array<string, mixed>>,
 *   log_rows:array<int, array<string, mixed>>,
 * } $ctx */
$ctx = $ctx;
?>

<section class="dsgo-app-tab dsgo-cron-tab" aria-labelledby="dsgo-cron-heading">
    <h2 id="dsgo-cron-heading"><?php esc_html_e('Scheduled jobs', 'designsetgo-apps'); ?></h2>
    <p class="dsgo-app-tab__hint">
        <?php esc_html_e(
            'WP-cron fires approximately at the scheduled time, depending on site traffic. Low-traffic sites should consider configuring a server-side cron pointed at wp-cron.php.',
            'designsetgo-apps',
        ); ?>
    </p>

    <table class="widefat striped dsgo-cron-table">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Job ID', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Ability', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Schedule', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Next fire', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'designsetgo-apps'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ctx['jobs'] as $job) :
            $hook        = \DSGo_Apps\CronScheduler::hook($ctx['app_id'], (string) $job['id']);
            $next_ts     = wp_next_scheduled($hook);
            $next_label  = $next_ts
                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_ts)
                : __('— not scheduled', 'designsetgo-apps');
            $schedule    = (string) $job['schedule'];
            if (!empty($job['time'])) {
                $schedule .= ' @ ' . $job['time'];
            }
            if (isset($job['day_of_week'])) {
                $schedule .= ' (' . $job['day_of_week'] . ')';
            }
        ?>
            <tr>
                <td><code><?php echo esc_html((string) $job['id']); ?></code></td>
                <td><code><?php echo esc_html((string) $job['ability']); ?></code></td>
                <td><?php echo esc_html($schedule); ?></td>
                <td><?php echo esc_html($next_label); ?></td>
                <td>
                    <button
                        type="button"
                        class="button dsgo-cron-run-now"
                        data-job-id="<?php echo esc_attr((string) $job['id']); ?>"
                    >
                        <?php esc_html_e('Run now', 'designsetgo-apps'); ?>
                    </button>
                </td>
            </tr>
            <tr class="dsgo-cron-run-result" data-for="<?php echo esc_attr((string) $job['id']); ?>" hidden>
                <td colspan="5"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e('Recent runs', 'designsetgo-apps'); ?></h2>
    <?php if ($ctx['log_rows'] === []) : ?>
        <p class="dsgo-app-tab__empty"><?php esc_html_e('No cron runs recorded yet.', 'designsetgo-apps'); ?></p>
    <?php else : ?>
        <table class="widefat striped dsgo-cron-log">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Fired', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Job', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Duration', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Error', 'designsetgo-apps'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ctx['log_rows'] as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['fired_at']); ?></td>
                    <td><code><?php echo esc_html((string) $row['job_id']); ?></code></td>
                    <td>
                        <?php if ($row['status'] === 'ok') : ?>
                            <span class="dsgo-status dsgo-status--ok">ok</span>
                        <?php else : ?>
                            <span class="dsgo-status dsgo-status--error">error</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) $row['duration_ms']); ?> ms</td>
                    <td>
                        <?php if (!empty($row['error_code'])) : ?>
                            <code><?php echo esc_html((string) $row['error_code']); ?></code>
                        <?php endif; ?>
                        <?php if (!empty($row['error_msg'])) : ?>
                            <div class="dsgo-cron-log__msg"><?php echo esc_html((string) $row['error_msg']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
