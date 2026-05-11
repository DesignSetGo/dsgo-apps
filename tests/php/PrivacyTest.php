<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use DSGo_Apps\Privacy;
use DSGo_Apps\Storage;
use WP_UnitTestCase;

class PrivacyTest extends WP_UnitTestCase {

    private int $app_post_id;

    public function set_up(): void {
        parent::set_up();
        $this->app_post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'sample',
            'post_title'  => 'Sample App',
        ]);
    }

    public function tear_down(): void {
        // Email-log options use a dynamic prefix; clean up explicitly.
        delete_option('dsgo_apps_email_log_sample');
        delete_option('dsgo_apps_email_log_other');
        parent::tear_down();
    }

    public function test_register_wires_filters_and_actions(): void {
        // Reset and re-register so the assertion is deterministic regardless
        // of plugin-bootstrap order in earlier tests.
        remove_all_filters('wp_privacy_personal_data_exporters');
        remove_all_filters('wp_privacy_personal_data_erasers');
        remove_all_actions('admin_init');

        Privacy::register();

        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $erasers   = apply_filters('wp_privacy_personal_data_erasers',   []);

        $this->assertArrayHasKey(Privacy::EXPORTER_KEY, $exporters);
        $this->assertArrayHasKey(Privacy::EXPORTER_KEY, $erasers);
        $this->assertTrue(has_action('admin_init', [Privacy::class, 'register_policy_content']) !== false);
    }

    public function test_exporter_returns_user_storage_values(): void {
        $uid = $this->factory->user->create(['user_email' => 'alice@example.com']);
        Storage::user_set($this->app_post_id, $uid, 'pref', ['mode' => 'dark']);

        $result = Privacy::export_personal_data('alice@example.com');

        $this->assertTrue($result['done']);
        $this->assertNotEmpty($result['data']);

        $storage_rows = array_filter(
            $result['data'],
            static fn (array $row): bool => $row['group_id'] === 'designsetgo-apps-user-storage',
        );
        $this->assertCount(1, $storage_rows);

        $row = array_values($storage_rows)[0];
        $values = array_column($row['data'], 'value', 'name');
        $this->assertSame('Sample App', $values['App']);
        $this->assertSame('pref', $values['Key']);
        $this->assertStringContainsString('dark', $values['Value']);
    }

    public function test_exporter_returns_email_log_entries_for_matching_hash(): void {
        $email = 'bob@example.com';
        update_option('dsgo_apps_email_log_sample', [
            [
                'app_id'         => 'sample',
                'recipient_type' => 'current_user',
                'recipient_hash' => hash('sha256', $email),
                'subject'        => 'Welcome',
                'sent'           => true,
                'timestamp'      => 1_700_000_000,
            ],
            [
                'app_id'         => 'sample',
                'recipient_type' => 'admin',
                'recipient_hash' => hash('sha256', 'someoneelse@example.com'),
                'subject'        => 'Other',
                'sent'           => true,
                'timestamp'      => 1_700_000_001,
            ],
        ]);

        $result = Privacy::export_personal_data($email);

        $email_rows = array_values(array_filter(
            $result['data'],
            static fn (array $row): bool => $row['group_id'] === 'designsetgo-apps-email-log',
        ));
        $this->assertCount(1, $email_rows);
        $values = array_column($email_rows[0]['data'], 'value', 'name');
        $this->assertSame('Welcome', $values['Subject']);
        $this->assertSame('current_user', $values['Recipient type']);
    }

    public function test_eraser_removes_user_storage_and_email_entries(): void {
        $email = 'carol@example.com';
        $uid   = $this->factory->user->create(['user_email' => $email]);
        Storage::user_set($this->app_post_id, $uid, 'pref', 'value');

        update_option('dsgo_apps_email_log_sample', [
            [
                'app_id'         => 'sample',
                'recipient_type' => 'current_user',
                'recipient_hash' => hash('sha256', $email),
                'subject'        => 'Hi',
                'sent'           => true,
                'timestamp'      => 1_700_000_000,
            ],
        ]);

        $result = Privacy::erase_personal_data($email);

        $this->assertTrue($result['done']);
        $this->assertTrue($result['items_removed']);
        $this->assertNull(Storage::user_get($this->app_post_id, $uid, 'pref'));
        // Empty log is removed via delete_option(); the default sentinel comes back.
        $this->assertSame('absent', get_option('dsgo_apps_email_log_sample', 'absent'));
    }

    public function test_eraser_leaves_unrelated_email_entries_alone(): void {
        update_option('dsgo_apps_email_log_other', [
            [
                'app_id'         => 'other',
                'recipient_type' => 'admin',
                'recipient_hash' => hash('sha256', 'kept@example.com'),
                'subject'        => 'Kept',
                'sent'           => true,
                'timestamp'      => 1_700_000_000,
            ],
        ]);

        $result = Privacy::erase_personal_data('removed@example.com');

        $this->assertFalse($result['items_removed']);
        $log = get_option('dsgo_apps_email_log_other');
        $this->assertCount(1, $log);
        $this->assertSame('Kept', $log[0]['subject']);
    }

    public function test_policy_content_hook_is_registered_on_admin_init(): void {
        // wp_add_privacy_policy_content() emits a `_doing_it_wrong` notice if
        // called before admin context is set up; we don't fire it here, just
        // confirm the registration target is `admin_init` so production calls
        // are well-timed.
        remove_all_actions('admin_init');
        Privacy::register();

        $this->assertNotFalse(has_action('admin_init', [Privacy::class, 'register_policy_content']));
    }
}
