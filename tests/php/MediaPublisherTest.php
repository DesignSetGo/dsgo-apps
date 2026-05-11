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
}
