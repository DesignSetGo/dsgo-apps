<?php
/**
 * Per-app Webhooks tab template. Rendered by AdminPage::render_webhooks_tab().
 *
 * Read-only v1: lists declared endpoints with copy-ready callback URLs
 * and the most-recent log rows. The "Send test payload" form + JS
 * ship in a follow-up commit (admin-ajax handler for
 * `dsgo_apps_webhook_send_test`).
 *
 * $ctx:
 *   - app_id    string
 *   - app_name  string
 *   - endpoints array<int, array<string, mixed>>
 *   - log_rows  array<int, array<string, mixed>>
 *   - pro_gate  bool  ProFeatureGate::is_enabled('webhooks')
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var array{
 *   app_id:string,
 *   app_name:string,
 *   endpoints:array<int, array<string, mixed>>,
 *   log_rows:array<int, array<string, mixed>>,
 *   pro_gate:bool,
 * } $ctx */
$ctx = $ctx;

$rest_base = rest_url('dsgo/v1/webhooks/' . $ctx['app_id'] . '/');
?>

<section class="dsgo-app-tab dsgo-webhooks-tab" aria-labelledby="dsgo-webhooks-heading">
    <h2 id="dsgo-webhooks-heading"><?php esc_html_e('Webhook endpoints', 'designsetgo-apps'); ?></h2>

    <?php if (!$ctx['pro_gate']) : ?>
        <div class="notice notice-warning inline">
            <p>
                <?php esc_html_e(
                    'Webhook delivery requires DesignSetGo Apps Pro. Endpoints declared in the manifest will not receive requests until a Pro license is active.',
                    'designsetgo-apps',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <table class="widefat striped dsgo-webhooks-table">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Endpoint ID', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Ability', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Auth', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Async', 'designsetgo-apps'); ?></th>
                <th scope="col"><?php esc_html_e('Callback URL', 'designsetgo-apps'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ctx['endpoints'] as $endpoint) :
            $auth  = isset($endpoint['auth']) && is_array($endpoint['auth']) ? $endpoint['auth'] : [];
            $label = isset($auth['type']) ? (string) $auth['type'] : '—';
            if (($auth['type'] ?? '') === 'hmac-sha256' && isset($auth['scheme'])) {
                $label .= ' / ' . (string) $auth['scheme'];
            }
            $async_label = !empty($endpoint['async'])
                ? __('queued', 'designsetgo-apps')
                : __('inline', 'designsetgo-apps');
            $callback_url = $rest_base . (string) $endpoint['id'];
        ?>
            <tr>
                <td><code><?php echo esc_html((string) $endpoint['id']); ?></code></td>
                <td><code><?php echo esc_html((string) $endpoint['ability']); ?></code></td>
                <td><?php echo esc_html($label); ?></td>
                <td><?php echo esc_html($async_label); ?></td>
                <td>
                    <input
                        type="text"
                        readonly
                        class="regular-text code"
                        value="<?php echo esc_attr($callback_url); ?>"
                        onclick="this.select()"
                    >
                </td>
            </tr>
            <tr class="dsgo-webhook-test-row">
                <td colspan="5">
                    <details class="dsgo-webhook-test">
                        <summary><?php esc_html_e('Send a test payload', 'designsetgo-apps'); ?></summary>
                        <form class="dsgo-webhook-test-form" data-endpoint-id="<?php echo esc_attr((string) $endpoint['id']); ?>">
                            <label for="dsgo-test-body-<?php echo esc_attr((string) $endpoint['id']); ?>">
                                <?php esc_html_e('Body (JSON or raw text)', 'designsetgo-apps'); ?>
                            </label>
                            <textarea
                                id="dsgo-test-body-<?php echo esc_attr((string) $endpoint['id']); ?>"
                                class="dsgo-webhook-test-body"
                                rows="4"
                                cols="60"
                            >{"event":"test"}</textarea>
                            <p class="description">
                                <?php esc_html_e(
                                    'The handler signs this body with your configured secret and dispatches it through the full pipeline. The endpoint must already have its secret set on the Secrets tab.',
                                    'designsetgo-apps',
                                ); ?>
                            </p>
                            <button type="submit" class="button">
                                <?php esc_html_e('Send test payload', 'designsetgo-apps'); ?>
                            </button>
                            <span class="dsgo-webhook-test-result" hidden></span>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e('Recent deliveries', 'designsetgo-apps'); ?></h2>
    <?php if ($ctx['log_rows'] === []) : ?>
        <p class="dsgo-app-tab__empty"><?php esc_html_e('No webhook deliveries recorded yet.', 'designsetgo-apps'); ?></p>
    <?php else : ?>
        <table class="widefat striped dsgo-webhooks-log">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Received', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Endpoint', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Mode', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('HTTP', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Duration', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Error', 'designsetgo-apps'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ctx['log_rows'] as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['received_at']); ?></td>
                    <td><code><?php echo esc_html((string) $row['endpoint_id']); ?></code></td>
                    <td><?php echo (int) $row['async'] === 1 ? esc_html__('queued', 'designsetgo-apps') : esc_html__('inline', 'designsetgo-apps'); ?></td>
                    <td>
                        <?php if ($row['status'] === 'ok') : ?>
                            <span class="dsgo-status dsgo-status--ok">ok</span>
                        <?php else : ?>
                            <span class="dsgo-status dsgo-status--error">error</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) $row['http_status']); ?></td>
                    <td><?php echo esc_html((string) $row['duration_ms']); ?> ms</td>
                    <td>
                        <?php if (!empty($row['error_code'])) : ?>
                            <code><?php echo esc_html((string) $row['error_code']); ?></code>
                        <?php endif; ?>
                        <?php if (!empty($row['error_msg'])) : ?>
                            <div class="dsgo-webhooks-log__msg"><?php echo esc_html((string) $row['error_msg']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
