<?php
/**
 * GDPR/privacy integration: registers personal-data exporter, eraser,
 * and a privacy-policy content suggestion describing what DSGo Apps stores.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Privacy {

    public const EXPORTER_KEY = 'designsetgo-apps';

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers',   [self::class, 'register_eraser']);
        add_action('admin_init',                         [self::class, 'register_policy_content']);
    }

    /**
     * @param array<string, array{exporter_friendly_name:string, callback:callable}> $exporters
     * @return array<string, array{exporter_friendly_name:string, callback:callable}>
     */
    public static function register_exporter(array $exporters): array {
        $exporters[self::EXPORTER_KEY] = [
            'exporter_friendly_name' => __('DesignSetGo Apps', 'designsetgo-apps'),
            'callback'               => [self::class, 'export_personal_data'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, array{eraser_friendly_name:string, callback:callable}> $erasers
     * @return array<string, array{eraser_friendly_name:string, callback:callable}>
     */
    public static function register_eraser(array $erasers): array {
        $erasers[self::EXPORTER_KEY] = [
            'eraser_friendly_name' => __('DesignSetGo Apps', 'designsetgo-apps'),
            'callback'             => [self::class, 'erase_personal_data'],
        ];
        return $erasers;
    }

    /**
     * Export every per-user storage value the apps have written for this user,
     * plus any email-bridge audit-log entries whose hashed recipient matches
     * this email. Single-page exporter — DSGo data is bounded by the per-user
     * 256 KB quota and per-app 200-entry email log, so paging isn't needed.
     *
     * @return array{data: array<int, array{group_id:string, group_label:string, item_id:string, data:array<int, array{name:string, value:string}>}>, done: bool}
     */
    public static function export_personal_data(string $email_address, int $page = 1): array {
        $data = [];

        $user = get_user_by('email', $email_address);
        if ($user instanceof \WP_User) {
            $data = array_merge($data, self::collect_user_storage($user->ID));
        }

        $data = array_merge($data, self::collect_email_log_entries($email_address));

        return ['data' => $data, 'done' => true];
    }

    /**
     * Erase per-user storage + email-log entries for the given address.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
     */
    public static function erase_personal_data(string $email_address, int $page = 1): array {
        $items_removed = false;

        $user = get_user_by('email', $email_address);
        if ($user instanceof \WP_User) {
            if (self::erase_user_storage($user->ID) > 0) {
                $items_removed = true;
            }
        }

        if (self::erase_email_log_entries($email_address) > 0) {
            $items_removed = true;
        }

        return [
            'items_removed'  => $items_removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }

    /**
     * @return array<int, array{group_id:string, group_label:string, item_id:string, data:array<int, array{name:string, value:string}>}>
     */
    private static function collect_user_storage(int $user_id): array {
        $all_meta = get_user_meta($user_id);
        if (!is_array($all_meta)) return [];

        $rows = [];
        $value_prefix = 'dsgo_apps_storage_user_';
        $value_prefix_len = strlen($value_prefix);
        foreach ($all_meta as $meta_key => $values) {
            $meta_key = (string) $meta_key;
            if (strncmp($meta_key, $value_prefix, $value_prefix_len) !== 0) continue;

            $remainder = substr($meta_key, $value_prefix_len);
            // Layout: {app_post_id}_{key}
            if (!preg_match('/^(\d+)_(.+)$/', $remainder, $m)) continue;

            $app_post_id = (int) $m[1];
            $key         = (string) $m[2];
            $app_post    = get_post($app_post_id);
            $app_label   = $app_post instanceof \WP_Post && $app_post->post_title !== ''
                ? $app_post->post_title
                : sprintf('app #%d', $app_post_id);
            $value       = is_array($values) && isset($values[0]) ? (string) $values[0] : '';

            $rows[] = [
                'group_id'    => 'designsetgo-apps-user-storage',
                'group_label' => __('DesignSetGo Apps — App-specific storage', 'designsetgo-apps'),
                'item_id'     => sprintf('designsetgo-apps-storage-%d-%s', $app_post_id, $key),
                'data'        => [
                    ['name' => __('App', 'designsetgo-apps'),   'value' => $app_label],
                    ['name' => __('Key', 'designsetgo-apps'),   'value' => $key],
                    ['name' => __('Value', 'designsetgo-apps'), 'value' => $value],
                ],
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array{group_id:string, group_label:string, item_id:string, data:array<int, array{name:string, value:string}>}>
     */
    private static function collect_email_log_entries(string $email_address): array {
        $hash = hash('sha256', $email_address);
        $rows = [];
        foreach (self::email_log_option_names() as $option_name) {
            $log = get_option($option_name, []);
            if (!is_array($log)) continue;
            foreach ($log as $i => $entry) {
                if (!is_array($entry)) continue;
                if (($entry['recipient_hash'] ?? '') !== $hash) continue;

                $rows[] = [
                    'group_id'    => 'designsetgo-apps-email-log',
                    'group_label' => __('DesignSetGo Apps — Email audit log', 'designsetgo-apps'),
                    'item_id'     => sprintf('%s-%d', $option_name, $i),
                    'data'        => [
                        ['name' => __('App',            'designsetgo-apps'), 'value' => (string) ($entry['app_id'] ?? '')],
                        ['name' => __('Recipient type', 'designsetgo-apps'), 'value' => (string) ($entry['recipient_type'] ?? '')],
                        ['name' => __('Subject',        'designsetgo-apps'), 'value' => (string) ($entry['subject'] ?? '')],
                        ['name' => __('Sent',           'designsetgo-apps'), 'value' => !empty($entry['sent']) ? 'yes' : 'no'],
                        ['name' => __('Timestamp',      'designsetgo-apps'), 'value' => isset($entry['timestamp']) ? gmdate('c', (int) $entry['timestamp']) : ''],
                    ],
                ];
            }
        }
        return $rows;
    }

    private static function erase_user_storage(int $user_id): int {
        $all_meta = get_user_meta($user_id);
        if (!is_array($all_meta)) return 0;
        $removed = 0;
        foreach (array_keys($all_meta) as $meta_key) {
            $key = (string) $meta_key;
            if (strncmp($key, 'dsgo_apps_storage_user_',      23) === 0
             || strncmp($key, 'dsgo_apps_storage_size_user_', 28) === 0) {
                if (delete_user_meta($user_id, $key)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private static function erase_email_log_entries(string $email_address): int {
        $hash = hash('sha256', $email_address);
        $removed = 0;
        foreach (self::email_log_option_names() as $option_name) {
            $log = get_option($option_name, []);
            if (!is_array($log)) continue;
            $original_count = count($log);
            $filtered = array_values(array_filter(
                $log,
                static fn ($entry): bool =>
                    !is_array($entry) || ($entry['recipient_hash'] ?? '') !== $hash,
            ));
            if (count($filtered) === $original_count) continue;

            $removed += $original_count - count($filtered);
            if ($filtered === []) {
                delete_option($option_name);
            } else {
                update_option($option_name, $filtered, false);
            }
        }
        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private static function email_log_option_names(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('dsgo_apps_email_log_') . '%'
        ));
        return is_array($names) ? array_map('strval', $names) : [];
    }

    public static function register_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) return;

        $content = __(
            'When you use DesignSetGo Apps, the apps installed by your site administrator may save preferences, settings, or other data linked to your user account through the plugin\'s permissioned bridge. This data is stored in your WordPress user metadata, scoped per-app, and is never shared between apps.

If an app uses the email bridge to send mail, the plugin retains a per-app audit log that records the recipient type, the subject line, and a one-way SHA-256 hash of the recipient address (never the address itself). The audit log is capped at 200 entries per app.

Site administrators choose which apps to install and which permissions to grant. The plugin does not transmit personal data to DesignSetGo or any external service.',
            'designsetgo-apps'
        );
        wp_add_privacy_policy_content('DesignSetGo Apps', wp_kses_post(wpautop($content, false)));
    }
}
