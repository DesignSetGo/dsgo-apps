<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Bundle;
use DSGo_Apps\BundleError;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class BundleTest extends WP_UnitTestCase {

    public function test_dir_for_returns_path_under_uploads(): void {
        $dir = Bundle::dir_for('sample-app');
        $upload = wp_upload_dir();
        $this->assertStringStartsWith(trailingslashit($upload['basedir']) . 'dsgo-apps/', $dir);
        $this->assertStringEndsWith('/sample-app/', $dir);
    }

    public function test_safe_zip_entry_accepts_normal_paths(): void {
        $this->assertTrue(Bundle::is_safe_zip_entry('index.html'));
        $this->assertTrue(Bundle::is_safe_zip_entry('assets/main.js'));
        $this->assertTrue(Bundle::is_safe_zip_entry('img/logo.svg'));
    }

    public function test_safe_zip_entry_rejects_unsafe(): void {
        $this->assertFalse(Bundle::is_safe_zip_entry('../escape'));
        $this->assertFalse(Bundle::is_safe_zip_entry('/abs/path'));
        $this->assertFalse(Bundle::is_safe_zip_entry('a/../../escape'));
        $this->assertFalse(Bundle::is_safe_zip_entry('.hidden'));
        $this->assertFalse(Bundle::is_safe_zip_entry(''));
    }

    public function test_extension_allowlist(): void {
        $this->assertTrue(Bundle::is_allowed_extension('html'));
        $this->assertTrue(Bundle::is_allowed_extension('JS'));
        $this->assertFalse(Bundle::is_allowed_extension('php'));
        $this->assertFalse(Bundle::is_allowed_extension('exe'));
        $this->assertFalse(Bundle::is_allowed_extension(''));
    }

    public function test_recursive_delete(): void {
        $tmp = sys_get_temp_dir() . '/dsgo-bundle-test-' . uniqid();
        mkdir($tmp);
        mkdir($tmp . '/sub');
        file_put_contents($tmp . '/a.txt', 'a');
        file_put_contents($tmp . '/sub/b.txt', 'b');
        Bundle::recursive_delete($tmp);
        $this->assertFalse(is_dir($tmp));
    }

    public function test_install_validate_accepts_inline_bundle_with_inline_script(): void {
        // Inline executable <script> bodies are allowed — the inline renderer
        // stamps the per-request nonce so CSP authorizes them at the browser.
        // (Frameworks like Astro emit hydration / view-transition shims this
        // way.) The renderer's stamp pass is what makes them safe.
        $bundle_dir = $this->make_temp_bundle([
            'dsgo-app.json' => json_encode($this->minimal_inline_manifest()),
            'index.html' => '<!doctype html><html><body><script>console.log(1)</script></body></html>',
        ]);
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        Bundle::validate_post_extract($bundle_dir, $manifest);
        $this->assertTrue(true);
    }

    public function test_install_validate_rejects_inline_bundle_with_mismatched_script_nonce(): void {
        // An author who hand-wrote a non-matching nonce would silently fail
        // CSP at render time — louder failure here.
        $bundle_dir = $this->make_temp_bundle([
            'dsgo-app.json' => json_encode($this->minimal_inline_manifest()),
            'index.html' => '<!doctype html><html><body><script nonce="WRONG">alert(1)</script></body></html>',
        ]);
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        $this->expectException(BundleError::class);
        $this->expectExceptionMessage('match the per-request nonce');
        Bundle::validate_post_extract($bundle_dir, $manifest);
    }

    public function test_install_validate_accepts_clean_inline_bundle(): void {
        $bundle_dir = $this->make_temp_bundle([
            'dsgo-app.json' => json_encode($this->minimal_inline_manifest()),
            'index.html' => '<!doctype html><html><body><h1>Hello</h1></body></html>',
        ]);
        $manifest = Manifest::validate($this->minimal_inline_manifest());
        Bundle::validate_post_extract($bundle_dir, $manifest);
        $this->assertTrue(true);
    }

    public function test_install_validate_rejects_missing_route_file(): void {
        $manifest_arr = $this->minimal_inline_manifest();
        $manifest_arr['routes'] = [
            ['path' => '/', 'file' => 'index.html'],
            ['path' => '/missing', 'file' => 'missing.html'],
        ];
        $bundle_dir = $this->make_temp_bundle([
            'dsgo-app.json' => json_encode($manifest_arr),
            'index.html' => '<h1>ok</h1>',
        ]);
        $manifest = Manifest::validate($manifest_arr);
        $this->expectException(BundleError::class);
        $this->expectExceptionMessage('missing.html');
        Bundle::validate_post_extract($bundle_dir, $manifest);
    }

    // --- Dataset validation ----------------------------------------------

    public function test_dataset_missing_file_is_rejected(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            // Don't write the dataset file at all.
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
        });
        $this->expectExceptionCode_assertion('dataset_missing', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_invalid_json_is_rejected(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', '{not json');
        });
        $this->expectExceptionCode_assertion('dataset_invalid_json', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_must_be_top_level_array(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode(['not' => 'array']));
        });
        $this->expectExceptionCode_assertion('dataset_not_array', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_too_large_is_rejected(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            $rows = [];
            for ($i = 0; $i < 501; $i++) { $rows[] = ['id' => 'r' . $i]; }
            file_put_contents($dir . '/data/customers.json', json_encode($rows));
        });
        $this->expectExceptionCode_assertion('dataset_too_large', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_entry_must_be_object(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode([['id' => 'a'], 'not-an-object']));
        });
        $this->expectExceptionCode_assertion('dataset_entry_not_object', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_missing_id_field_is_rejected(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode([['id' => 'a'], ['name' => 'no-id']]));
        });
        $this->expectExceptionCode_assertion('dataset_missing_id', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_id_must_be_scalar(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode([['id' => ['nested', 'array']]]));
        });
        $this->expectExceptionCode_assertion('dataset_id_not_scalar', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_duplicate_id_is_rejected(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode([['id' => 'a'], ['id' => 'a']]));
        });
        $this->expectExceptionCode_assertion('dataset_duplicate_id', $bundle['dir'], $bundle['manifest']);
    }

    public function test_dataset_id_must_be_url_safe(): void {
        foreach (['has space', 'has/slash', 'has?query', 'has.dot', '..', '.'] as $bad_id) {
            $bundle = $this->make_bundle_with_dataset_route(static function (string $dir) use ($bad_id): void {
                file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
                file_put_contents($dir . '/data/customers.json', json_encode([['id' => $bad_id]]));
            });
            try {
                \DSGo_Apps\Bundle::validate_post_extract($bundle['dir'], $bundle['manifest']);
                $this->fail("expected rejection for id: $bad_id");
            } catch (\DSGo_Apps\BundleError $e) {
                $this->assertMatchesRegularExpression('/dataset_id_not_url_safe|dataset_id_dot/i', $e->error_code, $bad_id);
            }
        }
    }

    public function test_valid_dataset_passes_validation(): void {
        $bundle = $this->make_bundle_with_dataset_route(static function (string $dir): void {
            file_put_contents($dir . '/customer.html', '<!doctype html><html><body><h1>x</h1></body></html>');
            file_put_contents($dir . '/data/customers.json', json_encode([
                ['id' => 'alice', 'name' => 'Alice'],
                ['id' => 'bob',   'name' => 'Bob'],
            ]));
        });
        \DSGo_Apps\Bundle::validate_post_extract($bundle['dir'], $bundle['manifest']);
        $this->assertTrue(true); // no exception
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param callable(string):void $populate
     * @return array{dir:string, manifest:\DSGo_Apps\Manifest}
     */
    private function make_bundle_with_dataset_route(callable $populate): array {
        $dir = sys_get_temp_dir() . '/dsgo-ds-' . uniqid();
        mkdir($dir . '/data', 0755, true);
        file_put_contents($dir . '/index.html', '<!doctype html><html><body><h1>root</h1></body></html>');
        $populate($dir);
        $manifest = \DSGo_Apps\Manifest::validate([
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/customers/:id', 'file' => 'customer.html',
                 'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
            ],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]);
        return ['dir' => $dir, 'manifest' => $manifest];
    }

    private function expectExceptionCode_assertion(string $expected_code, string $dir, \DSGo_Apps\Manifest $manifest): void {
        try {
            \DSGo_Apps\Bundle::validate_post_extract($dir, $manifest);
            $this->fail("expected BundleError with code: $expected_code");
        } catch (\DSGo_Apps\BundleError $e) {
            $this->assertSame($expected_code, $e->error_code);
        }
    }

    private function minimal_inline_manifest(): array {
        return [
            'manifest_version' => 1,
            'id' => 'sample-inline',
            'name' => 'Sample',
            'version' => '0.1.0',
            'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => [
                'sandbox' => 'strict',
                'csp' => [
                    'script_src' => ['self'], 'style_src' => ['self'],
                    'img_src' => ['self'], 'connect_src' => ['self'],
                ],
            ],
        ];
    }

    private function make_temp_bundle(array $files): string {
        $dir = sys_get_temp_dir() . '/dsgo-bundle-' . uniqid();
        mkdir($dir, 0755, true);
        foreach ($files as $rel => $contents) {
            $abs = $dir . '/' . $rel;
            $parent = dirname($abs);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            file_put_contents($abs, $contents);
        }
        return $dir;
    }
}
