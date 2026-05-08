<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\EmailBridge;
use DSGo_Apps\EmailRecipient;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class EmailBridgeTest extends WP_UnitTestCase {

    /** @var array<int, array{to:string,subject:string,body:string,headers:array}> */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        $this->sent = [];
        EmailBridge::set_sender_for_tests(function ($to, $subject, $body, $headers) {
            $this->sent[] = compact('to', 'subject', 'body', 'headers');
            return true;
        });
        // Reset rate-limit transient + audit log between tests.
        delete_transient('dsgo_email_rate_sample_' . gmdate('YmdH'));
        delete_option('dsgo_apps_email_log_sample');
        update_option('admin_email', 'admin@example.test');
    }

    public function tear_down(): void {
        EmailBridge::set_sender_for_tests(null);
        parent::tear_down();
    }

    private function manifest(array $recipients = ['admin']): Manifest {
        return Manifest::validate([
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['email'], 'write' => []],
            'email' => ['recipients' => $recipients],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]);
    }

    public function test_admin_recipient_resolves_to_site_admin_email(): void {
        $result = EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('admin@example.test', $this->sent[0]['to']);
    }

    public function test_subject_prefixed_with_app_name_by_default(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertSame('[App: Sample] Hi', $this->sent[0]['subject']);
    }

    public function test_admin_can_disable_subject_prefix_per_app(): void {
        update_option('dsgo_apps_email_disable_prefix_sample', true);
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertSame('Hi', $this->sent[0]['subject']);
        delete_option('dsgo_apps_email_disable_prefix_sample');
    }

    public function test_recipient_not_in_manifest_rejected_with_permission_denied(): void {
        // Manifest only declares "admin"; app tries "current_user".
        $result = EmailBridge::send($this->manifest(['admin']), 0, [
            'to' => 'current_user', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
    }

    public function test_unknown_recipient_value_rejected_with_invalid_params(): void {
        $result = EmailBridge::send($this->manifest(), 0, [
            'to' => 'arbitrary@example.com', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function test_current_user_anonymous_rejected_with_not_authenticated(): void {
        $result = EmailBridge::send($this->manifest(['current_user']), 0, [
            'to' => 'current_user', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_authenticated', $result['code']);
    }

    public function test_current_user_logged_in_resolves_user_email(): void {
        $uid = self::factory()->user->create(['user_email' => 'user@example.test']);
        $result = EmailBridge::send($this->manifest(['current_user']), $uid, [
            'to' => 'current_user', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('user@example.test', $this->sent[0]['to']);
    }

    public function test_html_body_sanitized_via_wp_kses_post(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi',
            'body' => '<p>Hi</p><script>alert(1)</script>',
            'isHtml' => true,
        ]);
        $this->assertStringNotContainsString('<script>', $this->sent[0]['body']);
        $this->assertStringContainsString('<p>Hi</p>', $this->sent[0]['body']);
    }

    public function test_plain_body_strips_all_tags(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi',
            'body' => 'Hi <b>there</b>',
        ]);
        $this->assertStringNotContainsString('<b>', $this->sent[0]['body']);
    }

    public function test_html_mode_sets_content_type_header(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'isHtml' => true,
        ]);
        $found = false;
        foreach ($this->sent[0]['headers'] as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected text/html Content-Type header');
    }

    public function test_reply_to_header_set_when_valid(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
            'replyTo' => 'lead@example.test',
        ]);
        $found = false;
        foreach ($this->sent[0]['headers'] as $h) {
            if (stripos($h, 'Reply-To:') === 0 && strpos($h, 'lead@example.test') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected Reply-To header');
    }

    public function test_invalid_reply_to_rejected(): void {
        $result = EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
            'replyTo' => 'not-an-email',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function test_subject_too_long_rejected(): void {
        $result = EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => str_repeat('a', 201), 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function test_empty_subject_rejected(): void {
        $result = EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => '   ', 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function test_rate_limit_blocks_after_threshold(): void {
        // Cap is 100/hour. Use a filter to reduce for the test.
        $cap = 3;
        $filter = function () use ($cap) { return $cap; };
        add_filter('dsgo_apps_email_rate_limit_per_hour', $filter, 10);
        try {
            for ($i = 0; $i < $cap; $i++) {
                $r = EmailBridge::send($this->manifest(), 0, [
                    'to' => 'admin', 'subject' => "Msg $i", 'body' => 'Body',
                ]);
                $this->assertTrue($r['ok'], "send #$i should succeed");
            }
            $blocked = EmailBridge::send($this->manifest(), 0, [
                'to' => 'admin', 'subject' => 'overflow', 'body' => 'Body',
            ]);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('rate_limited', $blocked['code']);
        } finally {
            remove_filter('dsgo_apps_email_rate_limit_per_hour', $filter, 10);
        }
    }

    public function test_audit_log_records_each_send(): void {
        EmailBridge::send($this->manifest(), 0, [
            'to' => 'admin', 'subject' => 'Audit me', 'body' => 'Body',
        ]);
        $log = get_option('dsgo_apps_email_log_sample');
        $this->assertIsArray($log);
        $this->assertNotEmpty($log);
        $latest = end($log);
        $this->assertSame('sample', $latest['app_id']);
        $this->assertSame('admin', $latest['recipient_type']);
        $this->assertTrue($latest['sent']);
        $this->assertSame('Audit me', $latest['subject']);
        // Recipient hash, not plaintext.
        $this->assertSame(hash('sha256', 'admin@example.test'), $latest['recipient_hash']);
    }

    public function test_app_without_email_permission_rejected(): void {
        $arr = [
            'manifest_version' => 1, 'id' => 'noem', 'name' => 'NoEmail',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ];
        $manifest = Manifest::validate($arr);
        $result = EmailBridge::send($manifest, 0, [
            'to' => 'admin', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
    }
}
