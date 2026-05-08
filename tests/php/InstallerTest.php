<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Installer;
use DSGo_Apps\PostType;
use WP_UnitTestCase;
use ZipArchive;

class InstallerTest extends WP_UnitTestCase {

    protected int $admin_id;

    public function set_up(): void {
        parent::set_up();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        // Remove any bundle dirs (including stash remnants) left from previous runs.
        $uploads_base = wp_upload_dir()['basedir'] . '/dsgo-apps/';
        foreach ([
            'my-app', 'inject-test', 'csp-test', 'pi-test', 'rollback-test',
            'marketing-site', 'first-root', 'second-root', 'updatable-root',
            'concurrent-test', 'stale-lock-test', 'lock-release-test',
        ] as $id) {
            \DSGo_Apps\Bundle::recursive_delete($uploads_base . $id);
            // Also remove any stash dirs (e.g. rollback-test.previous-XXXXXX).
            foreach (glob($uploads_base . $id . '.previous-*') ?: [] as $stash) {
                \DSGo_Apps\Bundle::recursive_delete($stash);
            }
        }
        delete_option(\DSGo_Apps\Settings::OPTION_ROOT_APP_ID);
    }

    public function tear_down(): void {
        delete_option(\DSGo_Apps\Settings::OPTION_ROOT_APP_ID);
        delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
        parent::tear_down();
    }

    public function test_install_minimal_app(): void {
        $zip = $this->build_minimal_zip('my-app');
        $result = Installer::install($zip, $this->admin_id);
        $this->assertSame('my-app', $result->app_id);
        $this->assertNotEmpty($result->url);
        $post = get_page_by_path('my-app', OBJECT, PostType::SLUG);
        $this->assertNotNull($post);
        $this->assertSame('My App', $post->post_title);
    }

    public function test_install_rejects_zip_with_traversal_entry(): void {
        // A zip containing a `../wp-config.php` entry must be hard-rejected
        // before extraction. is_safe_zip_entry is unit-tested in BundleTest;
        // this asserts the safety check is wired into the full install path.
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-evil-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'evil-app',
            'name'             => 'Evil',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><body>x</body></html>');
        $zip->addFromString('../wp-config.php', '<?php echo "pwn";');
        $zip->close();

        try {
            Installer::install($tmp, $this->admin_id);
            $this->fail('Expected InstallerError for path-traversal zip entry');
        } catch (\DSGo_Apps\InstallerError $e) {
            $this->assertSame('unsafe_path', $e->error_code);
        }
        $this->assertNull(get_page_by_path('evil-app', OBJECT, PostType::SLUG));
        $this->assertFalse(is_dir(\DSGo_Apps\Bundle::dir_for('evil-app')));
    }

    public function test_install_rejects_zip_with_no_manifest(): void {
        // Most common real-world failure: user uploads the wrong zip.
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-no-manifest-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<!doctype html><html><body>x</body></html>');
        $zip->close();

        try {
            Installer::install($tmp, $this->admin_id);
            $this->fail('Expected InstallerError for missing manifest');
        } catch (\DSGo_Apps\InstallerError $e) {
            $this->assertSame('missing_manifest', $e->error_code);
        }
    }

    public function test_install_injects_bridge_client_script(): void {
        $zip = $this->build_minimal_zip('inject-test');
        Installer::install($zip, $this->admin_id);
        $entry_path = \DSGo_Apps\Bundle::dir_for('inject-test') . 'index.html';
        $html = file_get_contents($entry_path);
        $this->assertStringContainsString('bridge-client.js', $html);
        $this->assertMatchesRegularExpression('#<head>[^<]*<script[^>]+bridge-client\.js#is', $html);
    }

    public function test_install_injects_csp_meta(): void {
        $zip = $this->build_minimal_zip('csp-test');
        Installer::install($zip, $this->admin_id);
        $entry_path = \DSGo_Apps\Bundle::dir_for('csp-test') . 'index.html';
        $html = file_get_contents($entry_path);
        $this->assertMatchesRegularExpression('#<meta[^>]+http-equiv="Content-Security-Policy"[^>]+content="[^"]*default-src \'none\'[^"]*"#i', $html);
        $this->assertStringContainsString('script-src', $html);
    }

    public function test_install_does_not_leak_xml_pi(): void {
        $zip = $this->build_minimal_zip('pi-test');
        Installer::install($zip, $this->admin_id);
        $html = file_get_contents(\DSGo_Apps\Bundle::dir_for('pi-test') . 'index.html');
        $this->assertStringNotContainsString('<?xml', $html, 'DOMDocument UTF-8 hack must be stripped from output');
    }

    public function test_failed_update_restores_previous_bundle_from_stash(): void {
        // v0.1.0 → install OK; bundle has v1 marker file.
        // v0.2.0 (broken) → install fails after stash; rollback rename
        // must restore the v0.1.0 bundle exactly so the existing app keeps
        // serving instead of going dark.
        $zip_v1 = $this->build_minimal_zip('rollback-test',
            '<!doctype html><html><head><title>v1</title></head><body><p id="v1-marker">v0.1.0 served</p></body></html>');
        $result = Installer::install($zip_v1, $this->admin_id);
        $this->assertSame('rollback-test', $result->app_id);
        $bundle_dir = \DSGo_Apps\Bundle::dir_for('rollback-test');
        $this->assertFileExists($bundle_dir . 'index.html');
        $v1_html = file_get_contents($bundle_dir . 'index.html');
        $this->assertStringContainsString('v0.1.0 served', $v1_html);

        // Now a v0.2.0 zip that the post-extract validator will reject.
        // The manifest declares a route file that isn't in the zip.
        $zip_v2 = tempnam(sys_get_temp_dir(), 'dsgo-rollback-v2-');
        $zip = new ZipArchive();
        $zip->open($zip_v2, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'rollback-test',
            'name'             => 'My App',
            'version'          => '0.2.0',
            'entry'            => 'index.html',
            'isolation'        => 'inline',
            'routes'           => [['path' => '/', 'file' => 'missing.html']],
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'], 'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>v2</title></head><body>v0.2.0</body></html>');
        $zip->close();

        try {
            Installer::install($zip_v2, $this->admin_id);
            $this->fail('expected InstallerError because routes[0].file is missing');
        } catch (\DSGo_Apps\InstallerError $e) {
            // Bundle::validate_post_extract throws BundleError, mapped to
            // missing_route_file by the installer.
            $this->assertSame('missing_route_file', $e->error_code);
        }

        // The v0.1.0 bundle must still exist exactly as before — same dir,
        // same file contents — so visitors hitting the app keep getting the
        // working version.
        $this->assertTrue(is_dir($bundle_dir), 'rollback must restore the bundle dir');
        $this->assertSame($v1_html, file_get_contents($bundle_dir . 'index.html'),
            'rollback must restore the v0.1.0 entry HTML byte-for-byte');

        // No leftover stash dirs in the uploads root.
        $uploads_base = wp_upload_dir()['basedir'] . '/dsgo-apps/';
        $stashes = glob($uploads_base . 'rollback-test.previous-*') ?: [];
        $this->assertSame([], $stashes, 'rollback must clean up its stash dir');
    }

    public function test_install_rolls_back_on_invalid_html(): void {
        $zip = $this->build_minimal_zip('rollback-test', 'this is not a real html document');
        try {
            Installer::install($zip, $this->admin_id);
            $this->fail('expected InstallerError for unparseable HTML');
        } catch (\DSGo_Apps\InstallerError $e) {
            // Use $error_code (not $code — Exception::$code is the inherited int).
            $this->assertSame('invalid_entry_html', $e->error_code);
        }
        $this->assertFalse(is_dir(\DSGo_Apps\Bundle::dir_for('rollback-test')), 'rollback should remove extracted dir');
        $this->assertNull(get_page_by_path('rollback-test', OBJECT, \DSGo_Apps\PostType::SLUG));
    }

    public function test_inline_app_install_does_not_inject_csp_meta_in_html(): void {
        // The end-to-end coverage for this is in tests/e2e/inline-app.spec.ts
        // (Task 19). This documents the contract.
        $this->markTestSkipped('integration-only; covered by inline e2e in Task 19');
    }

    public function test_install_root_app_caches_root_id(): void {
        $zip = $this->build_inline_root_zip('marketing-site');
        Installer::install($zip, $this->admin_id);
        $this->assertSame('marketing-site', \DSGo_Apps\Settings::get_root_app_id());
    }

    public function test_second_root_install_is_rejected(): void {
        Installer::install($this->build_inline_root_zip('first-root'), $this->admin_id);
        try {
            Installer::install($this->build_inline_root_zip('second-root'), $this->admin_id);
            $this->fail('expected root_mount_conflict');
        } catch (\DSGo_Apps\InstallerError $e) {
            $this->assertSame('root_mount_conflict', $e->error_code);
        }
        $this->assertSame('first-root', \DSGo_Apps\Settings::get_root_app_id());
    }

    public function test_reinstalling_same_root_app_is_allowed(): void {
        $zip = $this->build_inline_root_zip('updatable-root');
        Installer::install($zip, $this->admin_id);
        // Same id again — counts as an update, not a conflict.
        $zip2 = $this->build_inline_root_zip('updatable-root');
        $result = Installer::install($zip2, $this->admin_id);
        $this->assertSame('updatable-root', $result->app_id);
        $this->assertSame('updatable-root', \DSGo_Apps\Settings::get_root_app_id());
    }

    protected function build_inline_root_zip(string $id): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-root-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => 'Root App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'inline',
            'mount'            => ['mode' => 'root'],
            'routes'           => [['path' => '/', 'file' => 'index.html']],
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'csp' => [
                'script_src'  => ['self'],
                'style_src'   => ['self'],
                'img_src'     => ['self'],
                'connect_src' => ['self'],
            ]],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>Root</title></head><body><h1>Root</h1></body></html>');
        $zip->close();
        return $tmp;
    }

    public function test_install_lock_blocks_concurrent_install_for_same_slug(): void {
        // Pre-acquire the lock as if a parallel install were in progress.
        // (Lock state is just a WP option, atomic via add_option, so this
        // simulates the race window without needing real concurrency.)
        $lock_key = 'dsgo_apps_install_lock_concurrent-test';
        delete_option($lock_key);
        add_option($lock_key, ['acquired_at' => time(), 'pid' => 99999], '', 'no');

        $zip = $this->build_minimal_zip('concurrent-test');
        try {
            Installer::install($zip, $this->admin_id);
            $this->fail('expected install_in_progress to be raised');
        } catch (\DSGo_Apps\InstallerError $e) {
            $this->assertSame('install_in_progress', $e->error_code);
            $this->assertStringContainsString('already in progress', $e->getMessage());
        }
        delete_option($lock_key);
    }

    public function test_stale_install_lock_is_cleared(): void {
        // A lock older than LOCK_TIMEOUT_SECONDS represents a crashed install;
        // a new caller should reclaim it instead of being told to retry forever.
        $lock_key = 'dsgo_apps_install_lock_stale-lock-test';
        delete_option($lock_key);
        add_option($lock_key, ['acquired_at' => time() - 600, 'pid' => 99999], '', 'no');

        $zip = $this->build_minimal_zip('stale-lock-test');
        $result = Installer::install($zip, $this->admin_id);
        $this->assertSame('stale-lock-test', $result->app_id);
        // Lock should be released by the time install returns.
        $this->assertFalse(get_option($lock_key));
    }

    public function test_install_releases_lock_on_success(): void {
        $lock_key = 'dsgo_apps_install_lock_lock-release-test';
        delete_option($lock_key);
        $zip = $this->build_minimal_zip('lock-release-test');
        Installer::install($zip, $this->admin_id);
        $this->assertFalse(get_option($lock_key), 'lock must be released after a successful install');
    }

    public function test_install_bumps_per_app_cache_version(): void {
        $opt = 'dsgo_app_cache_version_my-app';
        delete_option($opt);
        // Pre-seed the option so we can assert it changed.
        add_option($opt, 'pre-existing-value', '', 'no');

        Installer::install($this->build_minimal_zip('my-app'), $this->admin_id);

        $after = get_option($opt);
        $this->assertNotSame('pre-existing-value', $after);
        $this->assertNotEmpty($after);
        delete_option($opt);
    }

    protected function build_minimal_zip(string $id, string $entry_html = null): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => 'My App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', $entry_html ?? '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return $tmp;
    }

    public function test_install_registers_published_abilities(): void {
        $zip = $this->build_zip_with_publishes('publish-test', [
            ['name' => 'publish-test/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content'],
        ]);
        Installer::install($zip, $this->admin_id);

        $this->assertTrue(wp_has_ability('publish-test/foo'));
        \DSGo_Apps\AbilitiesPublisher::unregister_for_app('publish-test');  // cleanup
    }

    public function test_install_reinstall_diffs_published_abilities(): void {
        $zip1 = $this->build_zip_with_publishes('publish-diff', [
            ['name' => 'publish-diff/old', 'label' => 'Old', 'description' => 'd', 'category' => 'content'],
        ]);
        Installer::install($zip1, $this->admin_id);

        $zip2 = $this->build_zip_with_publishes('publish-diff', [
            ['name' => 'publish-diff/new', 'label' => 'New', 'description' => 'd', 'category' => 'content'],
        ]);
        Installer::install($zip2, $this->admin_id);

        $this->assertFalse(wp_has_ability('publish-diff/old'));
        $this->assertTrue(wp_has_ability('publish-diff/new'));
        \DSGo_Apps\AbilitiesPublisher::unregister_for_app('publish-diff');
    }

    private function build_zip_with_publishes(string $id, array $publishes): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1, 'id' => $id, 'name' => $id, 'version' => '0.1.0',
            'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities' => ['publishes' => $publishes],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return $tmp;
    }
}
