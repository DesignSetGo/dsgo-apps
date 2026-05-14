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
        $uploads_base = wp_upload_dir()['basedir'] . '/designsetgo-apps/';
        foreach ([
            'my-app', 'inject-test', 'csp-test', 'pi-test', 'rollback-test',
            'marketing-site', 'first-root', 'second-root', 'updatable-root',
            'concurrent-test', 'stale-lock-test', 'lock-release-test',
            'vault-recon', 'vault-stable', 'vault-first', 'vault-deleted',
            'publish-app', 'well-known-app',
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
        $uploads_base = wp_upload_dir()['basedir'] . '/designsetgo-apps/';
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

    // --- Bucket preview + approved-buckets meta (added 2026-05-09) ---

    public function test_preview_returns_dto_for_minimal_app(): void {
        $zip = $this->build_minimal_zip('my-app');
        $result = \DSGo_Apps\Installer::preview($zip, $this->admin_id);
        $this->assertSame('my-app',  $result->app_id);
        $this->assertSame('My App',  $result->name);
        $this->assertSame('0.1.0',   $result->version);
        $this->assertFalse($result->is_update);
        $this->assertNull($result->previously_approved);
        // No permissions declared → no buckets activate.
        $this->assertSame([], $result->buckets);
        $this->assertSame([], $result->new_buckets);
        $this->assertSame([], $result->removed_buckets);
        $this->assertIsString($result->rendered_html);
    }

    public function test_consent_html_surfaces_runtime_capability_notes(): void {
        // runtime.uses_wasm / uses_workers are not permission buckets, but the
        // install dialog must still surface them as informational notes so the
        // admin knows binary / background code is present.
        $zip = $this->build_zip([
            'dsgo-app.json' => json_encode([
                'manifest_version' => 1,
                'id'               => 'wasm-app',
                'name'             => 'WASM App',
                'version'          => '0.1.0',
                'entry'            => 'index.html',
                'isolation'        => 'iframe',
                'display'          => ['modes' => ['page'], 'default' => 'page'],
                'permissions'      => ['read' => [], 'write' => []],
                'runtime'          => [
                    'sandbox'      => 'strict',
                    'external_origins' => [],
                    'uses_wasm'    => true,
                    'uses_workers' => true,
                ],
            ]),
            'index.html' => '<!doctype html><html><head><title>x</title></head><body>x</body></html>',
        ]);
        $result = \DSGo_Apps\Installer::install($zip, $this->admin_id);
        $this->assertStringContainsString('Uses WebAssembly modules', $result->rendered_html);
        $this->assertStringContainsString('Uses Web Workers', $result->rendered_html);
    }

    public function test_consent_html_omits_runtime_notes_when_not_declared(): void {
        $zip    = $this->build_minimal_zip('plain-app');
        $result = \DSGo_Apps\Installer::install($zip, $this->admin_id);
        $this->assertStringNotContainsString('Uses WebAssembly modules', $result->rendered_html);
        $this->assertStringNotContainsString('Uses Web Workers', $result->rendered_html);
    }

    public function test_preview_detects_is_update_when_app_already_installed(): void {
        // First install — write meta as install would.
        $zip1 = $this->build_minimal_zip('my-app');
        \DSGo_Apps\Installer::install($zip1, $this->admin_id);
        // Preview a re-install zip.
        $zip2   = $this->build_minimal_zip('my-app');
        $result = \DSGo_Apps\Installer::preview($zip2, $this->admin_id);
        $this->assertTrue($result->is_update);
        $this->assertIsArray($result->previously_approved);
    }

    public function test_install_writes_active_buckets_post_meta_for_minimal_app(): void {
        $zip    = $this->build_minimal_zip('my-app');
        $result = \DSGo_Apps\Installer::install($zip, $this->admin_id);
        $meta   = get_post_meta($result->post_id, 'dsgo_apps_active_buckets', true);
        // Minimal app activates no buckets — meta is an empty array, not absent.
        $this->assertIsArray($meta);
        $this->assertSame([], $meta);
    }

    public function test_install_writes_active_buckets_for_app_with_active_buckets(): void {
        // Build a zip with permissions.read = ['posts', 'ai']  →  ReadContent + Ai
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'my-app',
            'name'             => 'My App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => ['posts', 'ai'], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();

        $result = \DSGo_Apps\Installer::install($tmp, $this->admin_id);
        $meta   = get_post_meta($result->post_id, 'dsgo_apps_active_buckets', true);
        $this->assertSame(['read_content', 'ai'], $meta);
    }

    public function test_preview_computes_new_buckets_against_previously_approved(): void {
        // First install with just posts (read_content only).
        $tmp1 = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $z1 = new ZipArchive();
        $z1->open($tmp1, ZipArchive::OVERWRITE);
        $z1->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'my-app',
            'name'             => 'My App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => ['posts'], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $z1->addFromString('index.html', '<!doctype html><html><head/><body/></html>');
        $z1->close();
        \DSGo_Apps\Installer::install($tmp1, $this->admin_id);

        // Preview an update with posts + ai (adds AI bucket).
        $tmp2 = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $z2 = new ZipArchive();
        $z2->open($tmp2, ZipArchive::OVERWRITE);
        $z2->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'my-app',
            'name'             => 'My App',
            'version'          => '0.2.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => ['posts', 'ai'], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $z2->addFromString('index.html', '<!doctype html><html><head/><body/></html>');
        $z2->close();

        $result = \DSGo_Apps\Installer::preview($tmp2, $this->admin_id);
        $this->assertTrue($result->is_update);
        $this->assertSame(['read_content'],         $result->previously_approved);
        $this->assertSame(['read_content', 'ai'],   $result->buckets);
        $this->assertSame(['ai'],                   $result->new_buckets);
        $this->assertSame([],                       $result->removed_buckets);
    }

    public function test_preview_computes_removed_buckets_when_app_drops_permission(): void {
        // First install with posts + ai. Update drops ai.
        $tmp1 = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $z1 = new ZipArchive();
        $z1->open($tmp1, ZipArchive::OVERWRITE);
        $z1->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1, 'id' => 'my-app', 'name' => 'My App', 'version' => '0.1.0',
            'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['posts', 'ai'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $z1->addFromString('index.html', '<!doctype html><html><head/><body/></html>');
        $z1->close();
        \DSGo_Apps\Installer::install($tmp1, $this->admin_id);

        $tmp2 = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $z2 = new ZipArchive();
        $z2->open($tmp2, ZipArchive::OVERWRITE);
        $z2->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1, 'id' => 'my-app', 'name' => 'My App', 'version' => '0.2.0',
            'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['posts'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $z2->addFromString('index.html', '<!doctype html><html><head/><body/></html>');
        $z2->close();

        $result = \DSGo_Apps\Installer::preview($tmp2, $this->admin_id);
        $this->assertSame(['read_content'], $result->buckets);
        $this->assertSame([],               $result->new_buckets);
        $this->assertSame(['ai'],           $result->removed_buckets);
    }

    public function test_install_registers_published_abilities(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        $zip = $this->build_zip_with_publishes('publish-test', [
            ['name' => 'publish-test/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content'],
        ]);
        Installer::install($zip, $this->admin_id);

        $this->assertTrue(wp_has_ability('publish-test/foo'));
        \DSGo_Apps\AbilitiesPublisher::unregister_for_app('publish-test');  // cleanup
        remove_all_filters('dsgo_apps_pro_feature_enabled');
    }

    public function test_install_reinstall_diffs_published_abilities(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
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
        remove_all_filters('dsgo_apps_pro_feature_enabled');
    }

    public function test_install_publishes_manifest_declared_images_to_media_library(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);

        $manifest = [
            'manifest_version' => 1,
            'id'    => 'publish-app',
            'name'  => 'Publish App',
            'version' => '0.1.0',
            'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self', 'data:'], 'connect_src' => ['self'],
            ]],
            'media' => ['publish' => ['og/*.png']],
        ];

        $tiny_png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEX/AAAZ4gk3AAAAAXRSTlPM0jRW/QAAAApJREFUeJxjYAAAAAIAAUivpHEAAAAASUVORK5CYII='
        );

        $zip_path = $this->build_zip([
            'dsgo-app.json' => json_encode($manifest),
            'index.html'    => '<!doctype html><html><head></head><body></body></html>',
            'og/hero.png'   => $tiny_png,
        ]);

        $result = \DSGo_Apps\Installer::install($zip_path, $admin_id);
        $this->assertSame('publish-app', $result->app_id);

        $attachments = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => \DSGo_Apps\MediaPublisher::SOURCE_META_KEY,
            'meta_value'  => 'publish-app',
            'numberposts' => -1,
        ]);
        $this->assertCount(1, $attachments, 'one attachment should appear in the media library after install');
        $this->assertSame('og/hero.png', get_post_meta($attachments[0]->ID, \DSGo_Apps\MediaPublisher::PATH_META_KEY, true));
    }

    /**
     * Build a zip from a filename => contents map. Values may be binary strings.
     *
     * @param array<string,string> $files
     */
    private function build_zip(array $files): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-media-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $tmp;
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

    // --- Vault reconciliation on update / uninstall (Phase 8) ---

    public function test_install_drops_vault_aliases_no_longer_in_manifest(): void {
        // Install v1 with two secrets and admin sets values for both.
        $zip1 = $this->build_zip_with_secrets('vault-recon', [
            ['alias' => 'OLD_KEY', 'description' => 'Removed at v2 — should be purged.'],
            ['alias' => 'KEPT_KEY', 'description' => 'Kept across update — value preserved.'],
        ]);
        \DSGo_Apps\Installer::install($zip1, $this->admin_id);
        \DSGo_Apps\Secret_Vault::set('vault-recon', 'OLD_KEY',  'sk_old');
        \DSGo_Apps\Secret_Vault::set('vault-recon', 'KEPT_KEY', 'sk_kept');

        // Re-install v2 with OLD_KEY removed from the manifest.
        $zip2 = $this->build_zip_with_secrets('vault-recon', [
            ['alias' => 'KEPT_KEY', 'description' => 'Kept across update — value preserved.'],
        ]);
        \DSGo_Apps\Installer::install($zip2, $this->admin_id);

        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-recon', 'OLD_KEY'),
            'orphaned alias must be purged on update');
        $this->assertSame('sk_kept', \DSGo_Apps\Secret_Vault::get('vault-recon', 'KEPT_KEY'),
            'declared aliases must keep their admin-entered values across updates');

        \DSGo_Apps\Secret_Vault::delete_all('vault-recon');
    }

    public function test_install_preserves_set_values_when_manifest_unchanged(): void {
        $zip = $this->build_zip_with_secrets('vault-stable', [
            ['alias' => 'STABLE_KEY', 'description' => 'Value should survive a redeploy.'],
        ]);
        \DSGo_Apps\Installer::install($zip, $this->admin_id);
        \DSGo_Apps\Secret_Vault::set('vault-stable', 'STABLE_KEY', 'sk_persisted');

        // Same manifest, redeployed (atomic update path).
        $zip2 = $this->build_zip_with_secrets('vault-stable', [
            ['alias' => 'STABLE_KEY', 'description' => 'Value should survive a redeploy.'],
        ]);
        \DSGo_Apps\Installer::install($zip2, $this->admin_id);

        $this->assertSame('sk_persisted', \DSGo_Apps\Secret_Vault::get('vault-stable', 'STABLE_KEY'));
        \DSGo_Apps\Secret_Vault::delete_all('vault-stable');
    }

    public function test_install_first_time_with_secrets_does_not_error(): void {
        // First install with a populated secrets[] block — reconciliation
        // helper must no-op (vault starts empty; nothing to delete).
        $zip = $this->build_zip_with_secrets('vault-first', [
            ['alias' => 'NEW_KEY', 'description' => 'First-install alias; admin has not entered a value yet.'],
        ]);
        $result = \DSGo_Apps\Installer::install($zip, $this->admin_id);
        $this->assertSame('vault-first', $result->app_id);
        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-first', 'NEW_KEY'));
        \DSGo_Apps\Secret_Vault::delete_all('vault-first');
    }

    public function test_delete_app_endpoint_purges_vault(): void {
        // Install + populate vault.
        $zip = $this->build_zip_with_secrets('vault-deleted', [
            ['alias' => 'SK', 'description' => 'Should not survive uninstall.'],
        ]);
        \DSGo_Apps\Installer::install($zip, $this->admin_id);
        \DSGo_Apps\Secret_Vault::set('vault-deleted', 'SK', 'sk_should_be_gone');
        $this->assertSame('sk_should_be_gone', \DSGo_Apps\Secret_Vault::get('vault-deleted', 'SK'));

        // Drive the REST delete endpoint that admins use for uninstall.
        wp_set_current_user($this->admin_id);
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');
        $req = new \WP_REST_Request('DELETE', '/dsgo/v1/apps/vault-deleted');
        $resp = $wp_rest_server->dispatch($req);
        $this->assertSame(200, $resp->get_status());

        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-deleted', 'SK'),
            'vault must be purged when the app is uninstalled via REST');
        // Belt-and-suspenders: the wp_options row should be gone too.
        $this->assertFalse(get_option('dsgo_apps_secrets_vault-deleted'));
    }

    // --- Lite app cap defaults (Task 1 of free/Pro split) ---

    public function test_lite_app_cap_defaults_to_null_for_back_compat(): void {
        remove_all_filters('dsgo_apps_lite_app_cap');
        $this->assertNull(Installer::lite_app_cap());
    }

    public function test_installer_does_not_reject_when_cap_filter_unset(): void {
        remove_all_filters('dsgo_apps_lite_app_cap');
        // Install two apps with distinct slugs; both must succeed when cap is null.
        Installer::install($this->build_minimal_zip('my-app'), $this->admin_id);
        $this->assertNotNull(get_page_by_path('my-app', OBJECT, PostType::SLUG));
        Installer::install($this->build_minimal_zip('csp-test'), $this->admin_id);
        $this->assertNotNull(get_page_by_path('csp-test', OBJECT, PostType::SLUG));
    }

    // --- .well-known/ extension-gate exemption ---

    public function test_install_accepts_extensionless_well_known_file(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'well-known-app',
            'name'             => 'WK App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->addFromString('.well-known/api-catalog', '{"linkset":[]}');
        $zip->addFromString('.well-known/agent-skills/index.json', '{"skills":[]}');
        $zip->addFromString('.well-known/agent-skills/build-a-dsgo-app/SKILL.md', "---\nname: build-a-dsgo-app\n---\n");
        $zip->close();

        $result = Installer::install($tmp, $this->admin_id);
        $this->assertSame('well-known-app', $result->app_id);

        $bundle_dir = wp_upload_dir()['basedir'] . '/designsetgo-apps/well-known-app';
        $this->assertFileExists($bundle_dir . '/.well-known/api-catalog');
        $this->assertFileExists($bundle_dir . '/.well-known/agent-skills/index.json');
        $this->assertFileExists($bundle_dir . '/.well-known/agent-skills/build-a-dsgo-app/SKILL.md');
    }

    public function test_install_still_rejects_forbidden_extension_outside_well_known(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'well-known-app',
            'name'             => 'WK App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->addFromString('evil.exe', 'MZ');
        $zip->close();

        $this->expectException(\DSGo_Apps\InstallerError::class);
        Installer::install($tmp, $this->admin_id);
    }

    /**
     * @dataProvider forbidden_well_known_entries
     */
    public function test_install_rejects_forbidden_files_inside_well_known(string $entry, string $contents): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => 'well-known-app',
            'name'             => 'WK App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->addFromString($entry, $contents);
        $zip->close();

        $this->expectException(\DSGo_Apps\InstallerError::class);
        Installer::install($tmp, $this->admin_id);
    }

    public function forbidden_well_known_entries(): array {
        return [
            'php'      => ['.well-known/evil.php', '<?php echo "pwn";'],
            'htaccess' => ['.well-known/.htaccess', 'AddType application/x-httpd-php .txt'],
        ];
    }

    private function build_zip_with_secrets(string $id, array $secrets): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-vault-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => 'Vault App ' . $id,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            // permissions.http must be present (and non-empty) for the
            // manifest validator to accept the secrets[] block — the
            // "secrets without a consumer" rule guards against orphaned
            // declarations.
            'permissions'      => ['read' => [], 'write' => [], 'http' => ['api.example.com']],
            'secrets'          => $secrets,
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return $tmp;
    }
}
