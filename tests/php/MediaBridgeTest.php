<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Manifest;
use DSGo_Apps\MediaBridge;
use WP_UnitTestCase;

class MediaBridgeTest extends WP_UnitTestCase {

    /** @var array<int, array<string, mixed>> Captured wp_handle_upload inputs. */
    private array $captured = [];

    /** @var int */
    private int $author_id;

    /** @var int */
    private int $subscriber_id;

    public function set_up(): void {
        parent::set_up();
        $this->captured = [];
        $this->author_id     = self::factory()->user->create(['role' => 'author']);
        $this->subscriber_id = self::factory()->user->create(['role' => 'subscriber']);

        // Stub wp_handle_upload so we don't touch the real filesystem in CI.
        // Returns a deterministic shape that mirrors a successful PNG upload.
        MediaBridge::set_upload_handler_for_tests(function (array $file): array {
            $this->captured[] = $file;
            $name = isset($file['name']) ? (string) $file['name'] : 'upload.bin';
            $type = isset($file['type']) ? (string) $file['type'] : 'image/png';
            $tmp  = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
            // Persist a tiny valid PNG into the upload dir so wp_insert_attachment
            // and wp_generate_attachment_metadata have a real file to inspect.
            $upload_dir = wp_upload_dir();
            $target = trailingslashit($upload_dir['path']) . $name;
            if (is_string($tmp) && $tmp !== '' && is_readable($tmp)) {
                @copy($tmp, $target);
            } else {
                file_put_contents($target, self::tiny_png_bytes());
            }
            return [
                'file' => $target,
                'url'  => trailingslashit($upload_dir['url']) . $name,
                'type' => $type,
            ];
        });

        // Reset rate-limit transient between tests.
        delete_transient('dsgo_media_rate_sample_' . gmdate('YmdH'));
    }

    public function tear_down(): void {
        MediaBridge::set_upload_handler_for_tests(null);
        parent::tear_down();
    }

    private function manifest(array $overrides = []): Manifest {
        $base = [
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self', 'data:'], 'connect_src' => ['self'],
            ]],
        ];
        return Manifest::validate(array_replace_recursive($base, $overrides));
    }

    private function fake_file(int $bytes = 64, string $mime = 'image/png', string $name = 'app-image.png'): array {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-test-');
        file_put_contents($tmp, str_repeat("\x00", max(1, $bytes)));
        return [
            'name'     => $name,
            'type'     => $mime,
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    public function test_upload_creates_attachment_owned_by_visitor_and_tagged_with_app_id(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        $result   = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());

        $this->assertTrue($result['ok'], 'expected ok=true, got: ' . ($result['message'] ?? ''));
        $this->assertIsArray($result['data']);
        $this->assertIsInt($result['data']['id']);
        $this->assertGreaterThan(0, $result['data']['id']);

        $attachment_id = $result['data']['id'];
        $post = get_post($attachment_id);
        $this->assertNotNull($post);
        $this->assertSame('attachment', $post->post_type);
        $this->assertSame($this->author_id, (int) $post->post_author);
        $this->assertSame('sample', get_post_meta($attachment_id, MediaBridge::SOURCE_META_KEY, true));
    }

    public function test_upload_disabled_when_manifest_opts_out(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest(['media' => ['uploads' => false]]);
        $result   = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
    }

    public function test_upload_rejects_user_without_upload_files_cap(): void {
        wp_set_current_user($this->subscriber_id);
        $manifest = $this->manifest();
        $result   = MediaBridge::upload($manifest, $this->subscriber_id, $this->fake_file());
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
    }

    public function test_upload_rejects_oversize_file(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        $file = $this->fake_file(1024);
        $file['size'] = MediaBridge::DEFAULT_MAX_BYTES + 1;
        $result = MediaBridge::upload($manifest, $this->author_id, $file);
        $this->assertFalse($result['ok']);
        $this->assertSame('payload_too_large', $result['code']);
    }

    public function test_upload_records_alt_text(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        $result = MediaBridge::upload(
            $manifest,
            $this->author_id,
            $this->fake_file(),
            ['alt_text' => 'A red square'],
        );
        $this->assertTrue($result['ok']);
        $this->assertSame(
            'A red square',
            get_post_meta($result['data']['id'], '_wp_attachment_image_alt', true),
        );
        $this->assertSame('A red square', $result['data']['alt_text']);
    }

    public function test_upload_honors_filename_override(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        MediaBridge::upload(
            $manifest,
            $this->author_id,
            $this->fake_file(64, 'image/png', 'whatever.png'),
            ['filename' => 'my-custom-name.png'],
        );
        $this->assertNotEmpty($this->captured);
        $this->assertSame('my-custom-name.png', $this->captured[0]['name']);
    }

    public function test_disabled_global_filter_blocks_upload(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        $cb = static fn (): bool => false;
        add_filter('dsgo_apps_media_upload_allowed', $cb);
        try {
            $result = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());
        } finally {
            remove_filter('dsgo_apps_media_upload_allowed', $cb);
        }
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
    }

    public function test_rate_limit_kicks_in_after_cap(): void {
        wp_set_current_user($this->author_id);
        $manifest = $this->manifest();
        $cap_filter = static fn (): int => 2;
        add_filter('dsgo_apps_media_rate_limit_per_hour', $cap_filter);
        try {
            $r1 = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());
            $r2 = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());
            $r3 = MediaBridge::upload($manifest, $this->author_id, $this->fake_file());
        } finally {
            remove_filter('dsgo_apps_media_rate_limit_per_hour', $cap_filter);
        }
        $this->assertTrue($r1['ok']);
        $this->assertTrue($r2['ok']);
        $this->assertFalse($r3['ok']);
        $this->assertSame('rate_limited', $r3['code']);
    }

    private static function tiny_png_bytes(): string {
        // 1x1 transparent PNG — small but real, so wp_generate_attachment_metadata
        // can detect dimensions when called by the bridge.
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        );
    }
}
