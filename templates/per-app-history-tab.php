<?php
/**
 * Per-app History tab — lists prior bundle versions with revert buttons.
 *
 * Context (passed from AdminPage::render_history_tab):
 *   $app_id          string  manifest id
 *   $app_name        string  display name
 *   $current_version string  installed version
 *   $history         list<array{ts:int,version:string,dir:string,...}> newest-first
 *   $admin_post_url  string  admin-post.php URL
 *   $back_url        string  this tab's URL (for "Back" / post-action redirect)
 *   $nonce_action    string  wp_nonce_field action for the revert form
 *
 * @package DSGo_Apps
 */

if (!defined('ABSPATH')) exit;

// Surface success / error notices from admin-post redirect.
$reverted     = isset($_GET['reverted']) ? (int) $_GET['reverted'] : 0;             // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$revert_error = isset($_GET['revert_error']) ? sanitize_key(wp_unslash((string) $_GET['revert_error'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ($reverted > 0) {
    echo '<div class="notice notice-success"><p>'
        . esc_html__('Reverted successfully. The previous version was archived as a new history entry — you can re-revert if needed.', 'designsetgo-apps')
        . '</p></div>';
}
if ($revert_error !== '') {
    echo '<div class="notice notice-error"><p>'
        . esc_html(sprintf(
            /* translators: %s: error code */
            __('Revert failed (%s). The active bundle is unchanged.', 'designsetgo-apps'),
            $revert_error,
        ))
        . '</p></div>';
}
?>

<div class="dsgo-history">
    <p class="description">
        <?php
        printf(
            /* translators: %s: max history count */
            esc_html__('The last %s versions of this app are retained on disk. Click "Revert to this version" to restore one; the current version becomes a new history entry so you can re-revert.', 'designsetgo-apps'),
            esc_html((string) \DSGo_Apps\Installer::MAX_HISTORY_ENTRIES),
        );
        ?>
    </p>

    <?php if (empty($history)) : ?>
        <p><?php esc_html_e('No history entries yet. The first install does not create a history entry; the second install will.', 'designsetgo-apps'); ?></p>
    <?php else : ?>
        <table class="widefat striped dsgo-history__table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Version', 'designsetgo-apps'); ?></th>
                    <th scope="col"><?php esc_html_e('Saved', 'designsetgo-apps'); ?></th>
                    <th scope="col" class="dsgo-history__actions"><span class="screen-reader-text"><?php esc_html_e('Actions', 'designsetgo-apps'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $entry) :
                    $ts       = (int) ($entry['ts'] ?? 0);
                    $version  = (string) ($entry['version'] ?? '');
                    $when_iso = $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : '';
                    // Show in site timezone for the human-readable label;
                    // keep ISO in the title attr for unambiguous reference.
                    $when_local = $ts > 0 ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $ts), 'M j, Y \a\t g:i a') : '';
                ?>
                    <tr>
                        <td><code>v<?php echo esc_html($version); ?></code></td>
                        <td>
                            <span title="<?php echo esc_attr($when_iso . ' UTC'); ?>">
                                <?php echo esc_html($when_local); ?>
                            </span>
                        </td>
                        <td class="dsgo-history__actions">
                            <form method="post" action="<?php echo esc_url($admin_post_url); ?>" class="dsgo-history__form">
                                <input type="hidden" name="action" value="dsgo_apps_revert_history">
                                <input type="hidden" name="app_id" value="<?php echo esc_attr($app_id); ?>">
                                <input type="hidden" name="ts" value="<?php echo esc_attr((string) $ts); ?>">
                                <?php wp_nonce_field($nonce_action); ?>
                                <button type="submit" class="button"
                                    onclick="return confirm(<?php echo esc_attr(
                                        wp_json_encode(sprintf(
                                            /* translators: %1$s: version, %2$s: timestamp */
                                            __('Revert "%1$s" to v%2$s? The current version will be archived as a new history entry.', 'designsetgo-apps'),
                                            $app_name,
                                            $version,
                                        ))
                                    ); ?>);">
                                    <?php esc_html_e('Revert to this version', 'designsetgo-apps'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
