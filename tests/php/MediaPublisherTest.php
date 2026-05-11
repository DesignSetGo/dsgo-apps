<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Manifest;
use DSGo_Apps\MediaPublisher;
use WP_UnitTestCase;

class MediaPublisherTest extends WP_UnitTestCase {

    private string $bundle_dir;

    public function set_up(): void {
        parent::set_up();
        $this->bundle_dir = trailingslashit(sys_get_temp_dir()) . 'dsgo-publisher-' . uniqid();
        wp_mkdir_p($this->bundle_dir);
    }

    public function tear_down(): void {
        $this->rrmdir($this->bundle_dir);
        parent::tear_down();
    }

    private function write_file(string $relative, string $contents): string {
        $abs = $this->bundle_dir . '/' . $relative;
        wp_mkdir_p(dirname($abs));
        file_put_contents($abs, $contents);
        return $abs;
    }

    private function rrmdir(string $path): void {
        if (!is_dir($path)) return;
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $sub = $path . '/' . $entry;
            is_dir($sub) ? $this->rrmdir($sub) : @unlink($sub);
        }
        @rmdir($path);
    }

    public function test_collect_returns_files_matching_a_simple_glob(): void {
        $this->write_file('og/hero.png', 'a');
        $this->write_file('og/social.png', 'b');
        $this->write_file('readme.txt', 'c');

        $matches = MediaPublisher::collect($this->bundle_dir, ['og/*.png']);

        sort($matches);
        $this->assertSame(['og/hero.png', 'og/social.png'], $matches);
    }

    public function test_collect_deduplicates_when_patterns_overlap(): void {
        $this->write_file('og/hero.png', 'a');

        $matches = MediaPublisher::collect($this->bundle_dir, ['og/*', '*.png']);

        $this->assertSame(['og/hero.png'], $matches);
    }

    public function test_collect_returns_empty_for_no_matches(): void {
        $this->write_file('og/hero.png', 'a');
        $matches = MediaPublisher::collect($this->bundle_dir, ['nope/*.jpg']);
        $this->assertSame([], $matches);
    }

    public function test_collect_treats_star_as_crossing_slashes(): void {
        $this->write_file('a/b/c/deep.png', 'x');
        $matches = MediaPublisher::collect($this->bundle_dir, ['a/*']);
        $this->assertSame(['a/b/c/deep.png'], $matches);
    }

    private static function tiny_png_bytes(): string {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEX/AAAZ4gk3AAAAAXRSTlPM0jRW/QAAAApJREFUeJxjYAAAAAIAAUivpHEAAAAASUVORK5CYII='
        );
    }

    private function manifest(array $globs): Manifest {
        return Manifest::validate([
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
            'media' => ['publish' => $globs],
        ]);
    }

    public function test_publish_creates_attachment_for_matched_file(): void {
        $this->write_file('og/hero.png', self::tiny_png_bytes());
        $manifest = $this->manifest(['og/*.png']);

        $result = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);

        $this->assertSame(1, $result->published);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failed);

        $attachments = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => MediaPublisher::SOURCE_META_KEY,
            'meta_value'  => 'sample',
            'numberposts' => -1,
        ]);
        $this->assertCount(1, $attachments);
        $this->assertSame('og/hero.png', get_post_meta($attachments[0]->ID, MediaPublisher::PATH_META_KEY, true));
        $this->assertNotSame('', (string) get_post_meta($attachments[0]->ID, MediaPublisher::HASH_META_KEY, true));
        $this->assertSame('image/png', $attachments[0]->post_mime_type);
    }

    public function test_publish_returns_empty_result_when_no_globs(): void {
        $this->write_file('og/hero.png', self::tiny_png_bytes());
        $manifest = $this->manifest([]);
        $result = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);
        $this->assertSame(0, $result->published + $result->updated + $result->skipped + $result->failed);
    }

    public function test_republish_with_same_contents_is_skipped(): void {
        $this->write_file('og/hero.png', self::tiny_png_bytes());
        $manifest = $this->manifest(['og/*.png']);

        $first = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);
        $this->assertSame(1, $first->published);

        $second = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);
        $this->assertSame(0, $second->published);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->skipped);
        $this->assertSame(0, $second->failed);
    }

    public function test_republish_with_changed_contents_updates_in_place(): void {
        $abs = $this->write_file('og/hero.png', self::tiny_png_bytes());
        $manifest = $this->manifest(['og/*.png']);

        $first = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);
        $this->assertSame(1, $first->published);

        $attachment_id = (int) get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => MediaPublisher::SOURCE_META_KEY,
            'meta_value'  => 'sample',
            'fields'      => 'ids',
            'numberposts' => 1,
        ])[0];

        // Append a byte so the SHA-256 differs; still a valid PNG for sideload purposes
        // because tiny_png_bytes() is a complete IEND-terminated stream and WP only
        // checks the leading magic bytes for mime detection. If WP's stricter
        // image-validation rejects the appended bytes, use a fresh tiny_png_bytes()
        // variant; either way, the hash differs.
        file_put_contents($abs, self::tiny_png_bytes() . "\x00");

        $second = MediaPublisher::publish_for_app($manifest, $this->bundle_dir);
        $this->assertSame(0, $second->published);
        $this->assertSame(1, $second->updated);
        $this->assertSame(0, $second->skipped);
        $this->assertSame(0, $second->failed);

        // Same attachment ID is preserved across the update so any posts
        // referencing it continue to render the new file.
        $still_present = get_post($attachment_id);
        $this->assertNotNull($still_present);
        $this->assertSame('attachment', $still_present->post_type);
    }
}
