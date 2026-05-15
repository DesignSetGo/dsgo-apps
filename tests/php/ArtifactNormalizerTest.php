<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\ArtifactNormalizer;
use DSGo_Apps\ArtifactNormalizerError;
use DSGo_Apps\Bundle;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;
use ZipArchive;

class ArtifactNormalizerTest extends WP_UnitTestCase {

    public function test_happy_path_returns_zip_with_html_and_manifest(): void {
        $zip_path = ArtifactNormalizer::pack_html(
            '<!doctype html><h1>artifact</h1>',
            'sample-app',
            null,
            null,
        );
        $this->assertFileExists($zip_path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $html = $zip->getFromName('index.html');
        $manifest_raw = $zip->getFromName('dsgo-app.json');
        $zip->close();

        $this->assertStringContainsString('<h1>artifact</h1>', $html);
        $manifest = json_decode($manifest_raw, true);
        $this->assertSame(1, $manifest['manifest_version']);
        $this->assertSame('sample-app', $manifest['id']);
        $this->assertSame('sample-app', $manifest['name']);
        $this->assertSame('0.1.0', $manifest['version']);
        $this->assertSame('iframe', $manifest['isolation']);
        $this->assertSame('index.html', $manifest['entry']);
        $this->assertSame(['page', 'block'], $manifest['display']['modes']);
        $this->assertSame('page', $manifest['display']['default']);

        // Synthesized manifest must pass server-side validation — this is the
        // contract guarantee that lets the endpoint reuse Installer::install.
        Manifest::validate($manifest);

        $work_dir = dirname($zip_path);
        @unlink($zip_path);
        @rmdir($work_dir);
    }

    public function test_uses_provided_name_and_version(): void {
        $zip_path = ArtifactNormalizer::pack_html(
            '<x/>',
            'sample-app',
            'Pretty Name',
            '1.2.3',
        );
        $zip = new ZipArchive();
        $zip->open($zip_path);
        $manifest = json_decode($zip->getFromName('dsgo-app.json'), true);
        $zip->close();
        $this->assertSame('Pretty Name', $manifest['name']);
        $this->assertSame('1.2.3', $manifest['version']);
        $work_dir = dirname($zip_path);
        @unlink($zip_path);
        @rmdir($work_dir);
    }

    public function test_rejects_invalid_id(): void {
        try {
            ArtifactNormalizer::pack_html('<x/>', 'BAD ID', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('invalid_id', $e->error_code);
        }
    }

    public function test_rejects_two_char_id(): void {
        try {
            ArtifactNormalizer::pack_html('<x/>', 'ab', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('invalid_id', $e->error_code);
        }
    }

    public function test_rejects_invalid_version(): void {
        try {
            ArtifactNormalizer::pack_html('<x/>', 'sample-app', null, 'v1.2');
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('invalid_version', $e->error_code);
        }
    }

    public function test_rejects_empty_body(): void {
        try {
            ArtifactNormalizer::pack_html('', 'sample-app', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('empty_html', $e->error_code);
        }
    }

    public function test_rejects_body_over_size_cap(): void {
        $oversize = str_repeat('x', Bundle::max_total_bytes() + 1);
        try {
            ArtifactNormalizer::pack_html($oversize, 'sample-app', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('artifact_too_large', $e->error_code);
        }
    }

    public function test_rejects_invalid_utf8_body(): void {
        // 0xC3 0x28 is an invalid UTF-8 sequence (truncated 2-byte form).
        $bad = "\xC3\x28 not valid utf-8";
        try {
            ArtifactNormalizer::pack_html($bad, 'sample-app', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('invalid_html', $e->error_code);
        }
    }

    public function test_no_files_leak_in_work_dir_after_success(): void {
        $zip_path = ArtifactNormalizer::pack_html('<x/>', 'sample-app', null, null);
        $work_dir = dirname($zip_path);
        $this->assertFileExists($zip_path);
        // Only the zip file should remain; staged index.html and dsgo-app.json
        // must have been cleaned up.
        $remaining = array_values(array_diff(scandir($work_dir), ['.', '..']));
        $this->assertSame([basename($zip_path)], $remaining);
        @unlink($zip_path);
        @rmdir($work_dir);
    }

    public function test_pack_static_zip_synthesizes_manifest_for_claude_design_layout(): void {
        $input = self::make_input_zip([
            'home.html'         => '<!doctype html><h1>home</h1>',
            'styles.css'        => 'body{color:#000}',
            'src/app.jsx'       => 'const App = () => null;',
            'src/primitives.jsx'=> '/* primitives */',
            'uploads/home.md'   => '# home',
        ]);

        $zip_path = ArtifactNormalizer::pack_static_zip($input, 'design-app', 'Design App', '0.2.0');
        $this->assertFileExists($zip_path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $manifest = json_decode($zip->getFromName('dsgo-app.json'), true);
        $this->assertSame('home.html', $manifest['entry']);
        $this->assertSame('iframe', $manifest['isolation']);
        $this->assertSame('design-app', $manifest['id']);
        $this->assertSame('Design App', $manifest['name']);
        $this->assertSame('0.2.0', $manifest['version']);
        $this->assertSame(['page', 'block'], $manifest['display']['modes']);
        $this->assertSame('page', $manifest['display']['default']);

        // Original tree preserved (not flattened).
        $this->assertNotFalse($zip->getFromName('home.html'));
        $this->assertNotFalse($zip->getFromName('styles.css'));
        $this->assertNotFalse($zip->getFromName('src/app.jsx'));
        $this->assertNotFalse($zip->getFromName('src/primitives.jsx'));
        $this->assertNotFalse($zip->getFromName('uploads/home.md'));
        $zip->close();

        Manifest::validate($manifest);

        @unlink($zip_path);
        @rmdir(dirname($zip_path));
        @unlink($input);
        @rmdir(dirname($input));
    }

    public function test_pack_static_zip_prefers_index_html_over_home_html(): void {
        $input = self::make_input_zip([
            'home.html'  => '<x/>',
            'index.html' => '<x/>',
        ]);
        $zip_path = ArtifactNormalizer::pack_static_zip($input, 'idx-app', null, null);
        $zip = new ZipArchive();
        $zip->open($zip_path);
        $manifest = json_decode($zip->getFromName('dsgo-app.json'), true);
        $zip->close();
        $this->assertSame('index.html', $manifest['entry']);

        @unlink($zip_path);
        @rmdir(dirname($zip_path));
        @unlink($input);
        @rmdir(dirname($input));
    }

    public function test_pack_static_zip_skips_disallowed_extensions(): void {
        $input = self::make_input_zip([
            'home.html' => '<x/>',
            'src/build.ts' => 'export {}',
            'styles.css' => 'body{}',
        ]);
        $zip_path = ArtifactNormalizer::pack_static_zip($input, 'skip-app', null, null);
        $zip = new ZipArchive();
        $zip->open($zip_path);
        // .ts is silently dropped; the rest of the bundle survives.
        $this->assertFalse($zip->getFromName('src/build.ts'));
        $this->assertNotFalse($zip->getFromName('home.html'));
        $this->assertNotFalse($zip->getFromName('styles.css'));
        $zip->close();

        @unlink($zip_path);
        @rmdir(dirname($zip_path));
        @unlink($input);
        @rmdir(dirname($input));
    }

    public function test_pack_static_zip_rejects_zip_with_existing_manifest(): void {
        $input = self::make_input_zip([
            'home.html'     => '<x/>',
            'dsgo-app.json' => '{"manifest_version":1}',
        ]);
        try {
            ArtifactNormalizer::pack_static_zip($input, 'has-manifest', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('manifest_present', $e->error_code);
        } finally {
            @unlink($input);
            @rmdir(dirname($input));
        }
    }

    public function test_pack_static_zip_rejects_bundle_without_root_html(): void {
        $input = self::make_input_zip([
            'src/app.jsx' => 'const A = 1;',
            'styles.css'  => 'body{}',
        ]);
        try {
            ArtifactNormalizer::pack_static_zip($input, 'no-html', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('missing_entry_html', $e->error_code);
        } finally {
            @unlink($input);
            @rmdir(dirname($input));
        }
    }

    public function test_pack_static_zip_rejects_invalid_id(): void {
        $input = self::make_input_zip(['home.html' => '<x/>']);
        try {
            ArtifactNormalizer::pack_static_zip($input, 'BAD ID', null, null);
            $this->fail('expected ArtifactNormalizerError');
        } catch (ArtifactNormalizerError $e) {
            $this->assertSame('invalid_id', $e->error_code);
        } finally {
            @unlink($input);
            @rmdir(dirname($input));
        }
    }

    /**
     * @param array<string,string> $files name-in-zip → contents
     */
    private static function make_input_zip(array $files): string {
        $base = sys_get_temp_dir() . '/dsgo-artifact-test-' . wp_generate_password(8, false, false);
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('failed to create temp dir');
        }
        $zip_path = $base . '/input.zip';
        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $zip_path;
    }
}
