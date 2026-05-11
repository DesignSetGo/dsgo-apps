<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\DisplayMode;
use DSGo_Apps\ManifestError;
use DSGo_Apps\Permission;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class ManifestTest extends WP_UnitTestCase {

    public function test_display_mode_enum_values(): void {
        $this->assertSame('page',  DisplayMode::Page->value);
        $this->assertSame('block', DisplayMode::Block->value);
        $this->assertSame('admin', DisplayMode::Admin->value);
    }

    public function test_permission_enum_values(): void {
        $this->assertSame('site_info', Permission::SiteInfo->value);
        $this->assertSame('posts',     Permission::Posts->value);
        $this->assertSame('pages',     Permission::Pages->value);
        $this->assertSame('user',      Permission::User->value);
    }

    // --- raw_field() accessor (added 2026-05-09 for the bucket model) ---

    public function test_raw_field_returns_nested_value(): void {
        $manifest = new Manifest(
            id: 'sample', name: 'Sample', description: null, version: '0.1.0',
            author: null, entry: 'index.html',
            display_modes: [DisplayMode::Page], display_default: DisplayMode::Page,
            display_icon: null, permissions_read: [], external_origins: [],
            raw: ['scheduled' => ['jobs' => [['id' => 'x']]]],
        );
        $this->assertSame([['id' => 'x']], $manifest->raw_field('scheduled.jobs'));
    }

    public function test_raw_field_returns_null_when_path_missing(): void {
        $manifest = new Manifest(
            id: 'sample', name: 'Sample', description: null, version: '0.1.0',
            author: null, entry: 'index.html',
            display_modes: [DisplayMode::Page], display_default: DisplayMode::Page,
            display_icon: null, permissions_read: [], external_origins: [],
            raw: ['foo' => ['bar' => 1]],
        );
        $this->assertNull($manifest->raw_field('foo.baz'));
        $this->assertNull($manifest->raw_field('missing'));
        // Non-array intermediate (foo.bar is int) returns null for deeper paths.
        $this->assertNull($manifest->raw_field('foo.bar.deeper'));
    }

    public function test_raw_field_handles_top_level_and_nested_paths(): void {
        $manifest = new Manifest(
            id: 'sample', name: 'Sample', description: null, version: '0.1.0',
            author: null, entry: 'index.html',
            display_modes: [DisplayMode::Page], display_default: DisplayMode::Page,
            display_icon: null, permissions_read: [], external_origins: [],
            raw: ['permissions' => ['http' => ['api.stripe.com']]],
        );
        $this->assertSame(['http' => ['api.stripe.com']], $manifest->raw_field('permissions'));
        $this->assertSame(['api.stripe.com'], $manifest->raw_field('permissions.http'));
    }

    public function test_raw_field_defaults_to_empty_array_when_raw_not_passed(): void {
        $manifest = new Manifest(
            id: 'sample', name: 'Sample', description: null, version: '0.1.0',
            author: null, entry: 'index.html',
            display_modes: [DisplayMode::Page], display_default: DisplayMode::Page,
            display_icon: null, permissions_read: [], external_origins: [],
        );
        // No raw passed → all paths return null.
        $this->assertNull($manifest->raw_field('anything'));
        $this->assertNull($manifest->raw_field('nested.path'));
    }

    public function test_manifest_value_object_holds_fields(): void {
        $manifest = new Manifest(
            id: 'sample',
            name: 'Sample',
            description: 'desc',
            version: '0.1.0',
            author: 'me',
            entry: 'index.html',
            display_modes: [DisplayMode::Page],
            display_default: DisplayMode::Page,
            display_icon: null,
            permissions_read: [Permission::Posts],
            external_origins: [],
        );
        $this->assertSame('sample', $manifest->id);
        $this->assertSame([DisplayMode::Page], $manifest->display_modes);
        $this->assertSame([Permission::Posts], $manifest->permissions_read);
    }

    public function test_to_array_freezes_v1_invariants(): void {
        $manifest = new Manifest(
            id: 'sample',
            name: 'Sample',
            description: 'desc',
            version: '0.1.0',
            author: 'me',
            entry: 'index.html',
            display_modes: [DisplayMode::Page, DisplayMode::Block],
            display_default: DisplayMode::Page,
            display_icon: 'icon.svg',
            permissions_read: [Permission::SiteInfo, Permission::Posts],
            external_origins: ['https://api.openai.com'],
            isolation: 'iframe',
        );
        $expected = [
            'manifest_version' => 1,
            'id'               => 'sample',
            'name'             => 'Sample',
            'description'      => 'desc',
            'version'          => '0.1.0',
            'author'           => 'me',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => [
                'modes'   => ['page', 'block'],
                'default' => 'page',
                'icon'    => 'icon.svg',
            ],
            'permissions'      => [
                'read'  => ['site_info', 'posts'],
                'write' => [],
            ],
            'runtime'          => [
                'sandbox'          => 'strict',
                'external_origins' => ['https://api.openai.com'],
            ],
            'mount'            => ['mode' => 'prefixed'],
        ];
        $this->assertSame($expected, $manifest->to_array());
    }

    public function test_validate_minimum_valid_manifest(): void {
        $raw = [
            'manifest_version' => 1,
            'id'               => 'sample-app',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict'],
        ];
        $m = Manifest::validate($raw);
        $this->assertSame('sample-app', $m->id);
        $this->assertSame([DisplayMode::Page], $m->display_modes);
    }

    public function test_validate_rejects_wrong_manifest_version(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('manifest_version');
        Manifest::validate(['manifest_version' => 2, 'id' => 'a', 'name' => 'a', 'version' => '0.1.0', 'entry' => 'x.html', 'display' => ['modes' => ['page'], 'default' => 'page'], 'permissions' => ['read' => [], 'write' => []], 'runtime' => ['sandbox' => 'strict']]);
    }

    public function test_validate_rejects_inline_app_declaring_block_mode(): void {
        // Per BRIDGE-API.md: inline-mode apps only support "page" rendering
        // in v1 — block + admin require iframe isolation. The block embed
        // path serves the bundle statically (no bridge bootstrap), so an
        // inline-mode bundle with `block` declared would silently break
        // at runtime. Catch it at install time with a clear error.
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('isolation == "iframe"');
        Manifest::validate([
            'manifest_version' => 1,
            'id'               => 'inline-block',
            'name'             => 'Inline Block',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'inline',
            'routes'           => [['path' => '/', 'file' => 'index.html']],
            'display'          => ['modes' => ['page', 'block'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'], 'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]);
    }

    public function test_validate_rejects_missing_required_field(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('id');
        Manifest::validate(['manifest_version' => 1]);
    }

    public function test_validate_rejects_wrong_field_type(): void {
        $raw = [
            'manifest_version' => 1,
            'id'               => 12345, // wrong type
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict'],
        ];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('id');
        Manifest::validate($raw);
    }

    public function test_validate_rejects_invalid_id_format(): void {
        foreach (['ab', 'AB-cd', '1abc', 'has spaces', 'has/slash', str_repeat('a', 65)] as $bad) {
            try {
                Manifest::validate($this->raw_with(['id' => $bad]));
                $this->fail("expected $bad to be rejected");
            } catch (ManifestError $e) {
                $this->assertSame('id', $e->field);
            }
        }
    }

    public function test_validate_rejects_reserved_id(): void {
        // Includes the brand-critical trio: dsg, dsgo, designsetgo. Project naming
        // convention requires these stay reserved so apps don't collide with the prefix.
        foreach (['admin', 'api', 'wp-admin', 'wp-json', 'login', 'manifest', 'dsg', 'dsgo', 'designsetgo'] as $reserved) {
            try {
                Manifest::validate($this->raw_with(['id' => $reserved]));
                $this->fail("expected $reserved to be reserved");
            } catch (ManifestError $e) {
                $this->assertSame('id', $e->field);
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function test_validate_rejects_non_string_icon(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('display.icon');
        Manifest::validate($this->raw_with(['display' => ['icon' => 42]]));
    }

    public function test_validate_rejects_unsafe_entry_path(): void {
        foreach (['../escape.html', '/abs.html', 'no-extension', 'index.php'] as $bad) {
            try {
                Manifest::validate($this->raw_with(['entry' => $bad]));
                $this->fail("expected $bad to be rejected");
            } catch (ManifestError $e) {
                $this->assertSame('entry', $e->field);
            }
        }
    }

    public function test_validate_rejects_non_https_external_origin(): void {
        // 'https://example.com/' (trailing slash) is rejected because parse_url
        // treats '/' as a non-empty path and external_origins must be bare host[:port].
        foreach (['http://example.com', 'ftp://example.com', '*.example.com', 'https://example.com/path', 'https://example.com/'] as $bad) {
            try {
                Manifest::validate($this->raw_with(['runtime' => ['sandbox' => 'strict', 'external_origins' => [$bad]]]));
                $this->fail("expected $bad to be rejected");
            } catch (ManifestError $e) {
                $this->assertStringStartsWith('runtime.external_origins', $e->field);
            }
        }
    }

    public function test_validate_accepts_localhost_and_ip_origins(): void {
        // v1 accepts localhost, single-label hostnames, and dotted-quad IPs as
        // valid external_origins on purpose: app authors may whitelist dev
        // environments or internal services. If this becomes a concern (e.g.,
        // SSRF-adjacent risk vectors), tighten in a future task.
        $m = Manifest::validate($this->raw_with([
            'runtime' => ['sandbox' => 'strict', 'external_origins' => ['https://localhost:3000', 'https://192.168.1.1']],
        ]));
        $this->assertCount(2, $m->external_origins);
    }

    public function test_validate_rejects_malformed_hostname_origins(): void {
        foreach (['https://-bad.com', 'https://bad-.com', 'https://x..y', 'https://.', 'https://'] as $bad) {
            try {
                Manifest::validate($this->raw_with(['runtime' => ['sandbox' => 'strict', 'external_origins' => [$bad]]]));
                $this->fail("expected $bad to be rejected");
            } catch (ManifestError $e) {
                $this->assertStringStartsWith('runtime.external_origins', $e->field);
            }
        }
    }

    public function test_validate_accepts_valid_https_origins(): void {
        $m = Manifest::validate($this->raw_with([
            'runtime' => ['sandbox' => 'strict', 'external_origins' => ['https://api.openai.com', 'https://example.com:8443', 'https://sub.example.co.uk']],
        ]));
        $this->assertCount(3, $m->external_origins);
    }

    public function test_validate_rejects_overlong_name(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('name');
        Manifest::validate($this->raw_with(['name' => str_repeat('x', 81)]));
    }

    public function test_validate_rejects_overlong_description(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('description');
        Manifest::validate($this->raw_with(['description' => str_repeat('x', 501)]));
    }

    public function test_validate_rejects_overlong_author(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('author');
        Manifest::validate($this->raw_with(['author' => str_repeat('x', 121)]));
    }

    public function test_validate_rejects_non_semver_version(): void {
        foreach (['1', '1.2', '1.2.3.4', 'v1.2.3', '1.2.3-', 'abc', '1.0.0-bad..tag'] as $bad) {
            try {
                Manifest::validate($this->raw_with(['version' => $bad]));
                $this->fail("expected $bad to be rejected");
            } catch (ManifestError $e) {
                $this->assertSame('version', $e->field);
            }
        }
    }

    public function test_validate_accepts_valid_semver(): void {
        foreach (['0.1.0', '1.2.3', '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0+build.1', '2.3.4-rc.1+build.5'] as $good) {
            $m = Manifest::validate($this->raw_with(['version' => $good]));
            $this->assertSame($good, $m->version);
        }
    }

    public function test_validate_defaults_isolation_to_inline(): void {
        // When isolation is omitted entirely, default must be 'inline'.
        // Inline requires routes + csp, so we build a complete inline payload without the isolation key.
        $raw = array_replace_recursive([
            'manifest_version' => 1,
            'id'               => 'sample-app',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'routes'           => [['path' => '/', 'file' => 'index.html']],
            'runtime'          => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ], []);
        $m = Manifest::validate($raw);
        $this->assertSame('inline', $m->isolation);
    }

    public function test_validate_accepts_iframe_isolation_explicit(): void {
        $m = Manifest::validate($this->raw_with(['isolation' => 'iframe']));
        $this->assertSame('iframe', $m->isolation);
    }

    public function test_validate_rejects_unknown_isolation(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('isolation');
        Manifest::validate($this->raw_with(['isolation' => 'nope']));
    }

    public function test_validate_inline_requires_routes(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('routes');
        Manifest::validate($this->raw_with(['isolation' => 'inline']));
    }

    public function test_validate_inline_requires_root_route(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('routes[0].path');
        Manifest::validate($this->raw_with([
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/about', 'file' => 'about.html'],
            ],
        ]));
    }

    public function test_validate_accepts_minimal_inline_manifest(): void {
        $m = Manifest::validate($this->raw_with([
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/about', 'file' => 'about.html', 'title' => 'About'],
            ],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'],
                'style_src' => ['self'],
                'img_src' => ['self', 'data:'],
                'connect_src' => ['self'],
            ]],
        ]));
        $this->assertCount(2, $m->routes);
        $this->assertSame('/', $m->routes[0]['path']);
        $this->assertSame('About', $m->routes[1]['title']);
    }

    public function test_validate_accepts_embeds_allowlist(): void {
        $m = Manifest::validate($this->inline_raw_with([
            'runtime' => [
                'sandbox' => 'strict',
                'csp' => [
                    'script_src' => ['self'], 'style_src' => ['self'],
                    'img_src' => ['self'], 'connect_src' => ['self'],
                ],
                'embeds' => ['https://www.youtube.com', 'https://js.stripe.com'],
            ],
        ]));
        $this->assertSame(['https://www.youtube.com', 'https://js.stripe.com'], $m->embeds);
    }

    public function test_validate_rejects_non_https_embed_origin(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('runtime.embeds');
        Manifest::validate($this->inline_raw_with([
            'runtime' => [
                'sandbox' => 'strict',
                'csp' => [
                    'script_src' => ['self'], 'style_src' => ['self'],
                    'img_src' => ['self'], 'connect_src' => ['self'],
                ],
                'embeds' => ['http://insecure.example'],
            ],
        ]));
    }

    public function test_validate_rejects_param_path_without_dataset(): void {
        // :param paths require a dataset field (spec [3.5b]).
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('dataset');
        Manifest::validate($this->inline_raw_with(['routes' => [
            ['path' => '/', 'file' => 'index.html'],
            ['path' => '/c/:id', 'file' => 'c.html'],
        ]]));
    }

    public function test_validate_rejects_route_path_traversal(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('path');
        Manifest::validate($this->inline_raw_with(['routes' => [
            ['path' => '/', 'file' => 'index.html'],
            ['path' => '/../escape', 'file' => 'x.html'],
        ]]));
    }

    public function test_validate_rejects_duplicate_route_paths(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('routes');
        Manifest::validate($this->inline_raw_with(['routes' => [
            ['path' => '/', 'file' => 'index.html'],
            ['path' => '/', 'file' => 'index2.html'],
        ]]));
    }

    public function test_validate_rejects_route_file_with_dot_dot(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('file');
        Manifest::validate($this->inline_raw_with(['routes' => [
            ['path' => '/', 'file' => '../outside.html'],
        ]]));
    }

    public function test_validate_rejects_inline_with_external_origins(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('external_origins');
        Manifest::validate($this->inline_raw_with([
            'runtime' => [
                'sandbox' => 'strict',
                'external_origins' => ['https://api.example.com'],
                'csp' => [
                    'script_src' => ['self'], 'style_src' => ['self'],
                    'img_src' => ['self'], 'connect_src' => ['self'],
                ],
            ],
        ]));
    }

    public function test_validate_rejects_inline_without_csp(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('runtime.csp');
        Manifest::validate($this->inline_raw_with([
            'runtime' => ['sandbox' => 'strict', 'csp' => null],
        ]));
    }

    public function test_validate_rejects_iframe_with_csp(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('runtime.csp');
        Manifest::validate($this->raw_with([
            'isolation' => 'iframe',
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]));
    }

    public function test_validate_accepts_theme_defaults(): void {
        $m = Manifest::validate($this->inline_raw_with([]));
        $this->assertSame('none', $m->theme_wrap);
        $this->assertSame('none', $m->theme_container);
    }

    public function test_validate_rejects_unknown_theme_wrap(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('theme.wrap');
        Manifest::validate($this->inline_raw_with(['theme' => ['wrap' => 'pretty']]));
    }

    public function test_validate_rejects_v1_unsupported_theme_wrap(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('theme.wrap');
        Manifest::validate($this->inline_raw_with(['theme' => ['wrap' => 'full']]));
    }

    public function test_validate_rejects_csp_script_src_without_self(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('script_src');
        Manifest::validate($this->inline_raw_with([
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['https://cdn.example.com'],
                'style_src' => ['self'],
                'img_src' => ['self'],
                'connect_src' => ['self'],
            ]],
        ]));
    }

    public function test_validate_rejects_csp_data_in_script_src(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('script_src');
        Manifest::validate($this->inline_raw_with([
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self', 'data:'],
                'style_src' => ['self'],
                'img_src' => ['self'],
                'connect_src' => ['self'],
            ]],
        ]));
    }

    public function test_validate_mount_defaults_to_prefixed(): void {
        $m = Manifest::validate($this->inline_raw_with([]));
        $this->assertSame(\DSGo_Apps\MountMode::Prefixed, $m->mount_mode);
        $this->assertSame('prefixed', $m->to_array()['mount']['mode']);
    }

    public function test_validate_mount_accepts_root_for_inline(): void {
        $m = Manifest::validate($this->inline_raw_with(['mount' => ['mode' => 'root']]));
        $this->assertSame(\DSGo_Apps\MountMode::Root, $m->mount_mode);
    }

    public function test_validate_mount_accepts_root_for_iframe_with_page_mode(): void {
        $m = Manifest::validate($this->raw_with(['mount' => ['mode' => 'root']]));
        $this->assertSame(\DSGo_Apps\MountMode::Root, $m->mount_mode);
        $this->assertSame('iframe', $m->isolation);
    }

    public function test_validate_mount_rejects_root_when_modes_lack_page(): void {
        // iframe + block-only is the realistic shape that should be rejected:
        // a block-only app cannot render at the site root URL.
        try {
            Manifest::validate($this->raw_with([
                'mount'   => ['mode' => 'root'],
                'display' => ['modes' => ['block'], 'default' => 'block'],
            ]));
            $this->fail('expected mount=root to be rejected when display.modes lacks "page"');
        } catch (ManifestError $e) {
            $this->assertSame('mount.mode', $e->field);
            $this->assertStringContainsString('"page"', $e->getMessage());
        }
    }

    // --- routes[].claim (added 2026-05-10) ---

    public function test_validate_accepts_claim_always_on_root_inline_route(): void {
        $m = Manifest::validate($this->inline_raw_with([
            'mount'  => ['mode' => 'root'],
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/blog', 'file' => 'blog/index.html', 'claim' => 'always'],
            ],
        ]));
        $this->assertSame('always', $m->routes[1]['claim']);
    }

    public function test_validate_normalizes_missing_claim_to_null(): void {
        $m = Manifest::validate($this->inline_raw_with([
            'mount'  => ['mode' => 'root'],
            'routes' => [['path' => '/', 'file' => 'index.html']],
        ]));
        $this->assertNull($m->routes[0]['claim']);
    }

    public function test_validate_rejects_non_always_claim_string(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('routes[0].claim');
        Manifest::validate($this->inline_raw_with([
            'mount'  => ['mode' => 'root'],
            'routes' => [['path' => '/', 'file' => 'index.html', 'claim' => 'sometimes']],
        ]));
    }

    public function test_validate_rejects_boolean_claim(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('routes[0].claim');
        Manifest::validate($this->inline_raw_with([
            'mount'  => ['mode' => 'root'],
            'routes' => [['path' => '/', 'file' => 'index.html', 'claim' => true]],
        ]));
    }

    public function test_validate_rejects_claim_on_prefixed_mount(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('only allowed on root-mounted apps');
        Manifest::validate($this->inline_raw_with([
            'routes' => [['path' => '/', 'file' => 'index.html', 'claim' => 'always']],
        ]));
    }

    public function test_to_array_preserves_claim_on_routes(): void {
        // Storage round-trip: validate() returns a Manifest; to_array() is what
        // gets stored in post meta. If `claim` is dropped here, the runtime
        // dispatcher's `route_claims_path()` can never see it.
        $m = Manifest::validate($this->inline_raw_with([
            'mount'  => ['mode' => 'root'],
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/blog', 'file' => 'blog/index.html', 'claim' => 'always'],
            ],
        ]));
        $stored = $m->to_array();
        $this->assertArrayNotHasKey('claim', $stored['routes'][0]);
        $this->assertSame('always', $stored['routes'][1]['claim']);
    }

    public function test_validate_mount_rejects_unknown_mode(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('mount.mode');
        Manifest::validate($this->inline_raw_with(['mount' => ['mode' => 'subpath']]));
    }

    public function test_validate_mount_rejects_non_object(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('mount');
        Manifest::validate($this->inline_raw_with(['mount' => 'root']));
    }

    // --- permissions.justifications validation (added 2026-05-09 for the bucket model) ---

    public function test_validate_accepts_valid_justifications(): void {
        $manifest = Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts', 'ai'],
                'write' => [],
                'justifications' => [
                    'read_content' => 'Reads recent post titles for the homepage widget.',
                    'ai'           => 'Summarises the post bodies for a headline.',
                ],
            ],
        ]));
        // Round-trip via raw_field — the justifications are retained verbatim.
        $this->assertSame(
            'Reads recent post titles for the homepage widget.',
            $manifest->raw_field('permissions.justifications.read_content'),
        );
    }

    public function test_validate_rejects_unknown_justification_bucket(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('unknown_justification_bucket');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'not_a_bucket' => 'Should be rejected by the validator.',
                ],
            ],
        ]));
    }

    public function test_validate_rejects_justification_too_short(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('justification_too_short');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'read_content' => 'too short',     // 9 chars — under 10 minimum
                ],
            ],
        ]));
    }

    public function test_validate_rejects_justification_too_long(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('justification_too_long');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'read_content' => str_repeat('a', 281),   // 281 chars — over 280 max
                ],
            ],
        ]));
    }

    public function test_validate_rejects_justification_for_inactive_bucket(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('justification_for_inactive_bucket');
        // The app declares only `posts` in read — so AI is not active. Justifying
        // a bucket the app doesn't activate is a manifest error.
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'ai' => 'AI is justified but the app does not activate the AI bucket.',
                ],
            ],
        ]));
    }

    public function test_validate_rejects_justification_with_html(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('justification_invalid_chars');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'read_content' => 'Reads <strong>recent</strong> posts.',
                ],
            ],
        ]));
    }

    public function test_validate_rejects_justification_with_newlines(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('justification_invalid_chars');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    "read_content" => "Reads recent posts.\nWith a newline.",
                ],
            ],
        ]));
    }

    public function test_validate_rejects_non_string_justification_value(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('permissions.justifications.read_content');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => [
                    'read_content' => ['not a string'],
                ],
            ],
        ]));
    }

    public function test_validate_rejects_non_array_justifications(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('permissions.justifications');
        Manifest::validate($this->raw_with([
            'permissions' => [
                'read'  => ['posts'],
                'write' => [],
                'justifications' => 'not an array',
            ],
        ]));
    }

    public function test_validate_accepts_missing_justifications(): void {
        // justifications is optional — manifests without it validate fine.
        $m = Manifest::validate($this->raw_with([
            'permissions' => ['read' => ['posts'], 'write' => []],
        ]));
        $this->assertNull($m->raw_field('permissions.justifications'));
    }

    // --- top-level secrets + required_secrets + http.test_endpoint (Phase 1 of the HTTP proxy implementation) ---

    public function test_secrets_accepts_valid_entries(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [
            ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe secret key (sk_live_...)'],
            ['alias' => 'NOTION_TOKEN',  'description' => 'Notion integration token (secret_...)'],
        ];
        $m = Manifest::validate($arr);
        $this->assertCount(2, $m->secrets);
        $this->assertSame('STRIPE_SECRET', $m->secrets[0]['alias']);
        $this->assertSame('NOTION_TOKEN',  $m->secrets[1]['alias']);
    }

    public function test_secrets_alias_must_match_pattern(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('secrets_alias_format');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => 'lower_case', 'description' => 'Some description here.']];
        Manifest::validate($arr);
    }

    public function test_secrets_alias_must_start_with_letter(): void {
        $this->expectException(ManifestError::class);
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => '1KEY', 'description' => 'Some description here.']];
        Manifest::validate($arr);
    }

    public function test_secrets_description_min_length(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('secrets_description_length');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => 'MY_KEY', 'description' => 'Short']];
        Manifest::validate($arr);
    }

    public function test_secrets_description_max_length(): void {
        $this->expectException(ManifestError::class);
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => 'MY_KEY', 'description' => str_repeat('a', 281)]];
        Manifest::validate($arr);
    }

    public function test_secrets_rejects_duplicate_alias(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('duplicate');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [
            ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe key first occurrence.'],
            ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe key duplicate entry.'],
        ];
        Manifest::validate($arr);
    }

    public function test_secrets_forbidden_without_http_or_webhooks(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('secrets_misplaced');
        $arr = $this->valid_inline_manifest();
        // No permissions.http, no webhooks — secrets block is meaningless here.
        $arr['secrets'] = [['alias' => 'MY_KEY', 'description' => 'A long enough description here.']];
        Manifest::validate($arr);
    }

    public function test_secrets_allowed_with_webhooks_only_no_http(): void {
        // An app that only receives webhooks (no outbound HTTP) is still
        // allowed to declare secrets (signing keys). Updated 2026-05-11 to
        // honor the cron+webhooks plan's full contract: webhooks.endpoints
        // now requires `permissions.run: ["webhooks"]`, a published ability
        // with `execute_php`, and a properly-shaped auth block. The test
        // still proves the original claim — secrets are admissible when
        // webhooks is the only secret-consuming surface.
        $arr = $this->valid_inline_manifest();
        $arr['id']                       = 'mysite';
        $arr['permissions']['run']       = ['webhooks'];
        $arr['abilities']                = ['publishes' => [[
            'name'        => 'mysite/handle-stripe',
            'label'       => 'Handle Stripe event',
            'description' => 'Handle a Stripe webhook event for the test.',
            'category'    => 'commerce',
            'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
        ]]];
        $arr['webhooks'] = ['endpoints' => [[
            'id'      => 'stripe',
            'ability' => 'mysite/handle-stripe',
            'auth'    => [
                'type'         => 'hmac-sha256',
                'scheme'       => 'stripe',
                'secret_alias' => 'STRIPE_WEBHOOK_SECRET',
            ],
        ]]];
        $arr['secrets']  = [['alias' => 'STRIPE_WEBHOOK_SECRET', 'description' => 'Stripe webhook signing secret.']];
        $m = Manifest::validate($arr);
        $this->assertCount(1, $m->secrets);
        $this->assertCount(1, $m->webhook_endpoints());
    }

    public function test_required_secrets_defaults_to_all_declared_aliases(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [
            ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe secret first.'],
            ['alias' => 'NOTION_TOKEN',  'description' => 'Notion secret second.'],
        ];
        $m = Manifest::validate($arr);
        $this->assertSame(['STRIPE_SECRET', 'NOTION_TOKEN'], $m->required_secrets);
    }

    public function test_required_secrets_accepts_explicit_subset(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [
            ['alias' => 'STRIPE_SECRET', 'description' => 'Required at install.'],
            ['alias' => 'NOTION_TOKEN',  'description' => 'Optional secondary key.'],
        ];
        $arr['required_secrets'] = ['STRIPE_SECRET'];
        $m = Manifest::validate($arr);
        $this->assertSame(['STRIPE_SECRET'], $m->required_secrets);
    }

    public function test_required_secrets_explicit_empty_means_none_required(): void {
        // Pinning the contract: explicit `required_secrets: []` is honored as
        // "no aliases gated at install" — the deliberate opt-out for apps
        // whose secrets are optional. Authors who want all-required should
        // OMIT the field entirely (which defaults to all declared aliases).
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [
            ['alias' => 'STRIPE_SECRET', 'description' => 'Optional Stripe key.'],
        ];
        $arr['required_secrets'] = [];
        $m = Manifest::validate($arr);
        $this->assertSame([], $m->required_secrets);
    }

    public function test_required_secrets_rejects_duplicate_alias(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('required_secrets_duplicate');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => 'STRIPE_SECRET', 'description' => 'A long enough description here.']];
        $arr['required_secrets'] = ['STRIPE_SECRET', 'STRIPE_SECRET'];
        Manifest::validate($arr);
    }

    public function test_required_secrets_rejects_unknown_alias(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('required_secrets_unknown');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $arr['secrets'] = [['alias' => 'MY_KEY', 'description' => 'A long enough description here.']];
        $arr['required_secrets'] = ['UNKNOWN'];
        Manifest::validate($arr);
    }

    public function test_http_test_endpoint_accepts_https_url(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http']   = ['api.stripe.com'];
        $arr['http']                  = ['test_endpoint' => 'https://api.stripe.com/v1/charges'];
        $m = Manifest::validate($arr);
        $this->assertSame('https://api.stripe.com/v1/charges', $m->http_test_endpoint);
    }

    public function test_http_test_endpoint_rejects_non_https(): void {
        $this->expectException(ManifestError::class);
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http']   = ['api.stripe.com'];
        $arr['http']                  = ['test_endpoint' => 'http://api.stripe.com/v1/charges'];
        Manifest::validate($arr);
    }

    public function test_http_test_endpoint_defaults_to_null(): void {
        $arr = $this->valid_inline_manifest();
        $m = Manifest::validate($arr);
        $this->assertNull($m->http_test_endpoint);
    }

    // --- permissions.http (Phase 1 of the HTTP proxy implementation, 2026-05-10) ---

    public function test_permissions_http_accepts_exact_hostname(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com'];
        $m = Manifest::validate($arr);
        $this->assertSame(['api.stripe.com'], $m->permissions_http);
    }

    public function test_permissions_http_normalizes_to_lowercase(): void {
        // Hostnames are case-insensitive per RFC 3952 and parse_url returns
        // lowercase. The stored form must match so runtime comparisons work.
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['API.Stripe.COM', '*.NOTION.com'];
        $m = Manifest::validate($arr);
        $this->assertSame(['api.stripe.com', '*.notion.com'], $m->permissions_http);
    }

    public function test_permissions_http_accepts_single_label_wildcard(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['*.notion.com'];
        $m = Manifest::validate($arr);
        $this->assertSame(['*.notion.com'], $m->permissions_http);
    }

    public function test_permissions_http_accepts_multiple_hosts(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com', '*.notion.com', 'api.openai.com'];
        $m = Manifest::validate($arr);
        $this->assertSame(['api.stripe.com', '*.notion.com', 'api.openai.com'], $m->permissions_http);
    }

    public function test_permissions_http_defaults_to_empty_array_when_absent(): void {
        $arr = $this->valid_inline_manifest();
        unset($arr['permissions']['http']);
        $m = Manifest::validate($arr);
        $this->assertSame([], $m->permissions_http);
    }

    public function test_permissions_http_rejects_non_array(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('permissions.http');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = 'api.stripe.com';
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_non_string_entry(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('permissions.http');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.stripe.com', 42];
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_ipv4_literal(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_ip_host_forbidden');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['192.168.1.1'];
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_ipv6_literal(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_ip_host_forbidden');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['[::1]'];
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_multilevel_wildcard(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_invalid_host_pattern');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['*.*.stripe.com'];
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_wildcard_in_middle(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_invalid_host_pattern');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api.*.stripe.com'];
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_more_than_16_hosts(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_too_many_hosts');
        $arr = $this->valid_inline_manifest();
        $hosts = [];
        for ($i = 0; $i < 17; $i++) {
            $hosts[] = "api{$i}.example.com";
        }
        $arr['permissions']['http'] = $hosts;
        Manifest::validate($arr);
    }

    public function test_permissions_http_rejects_self_target(): void {
        // Filter home_url to a production-shape multi-label hostname so the
        // self-target check fires (single-label hosts like 'localhost' are
        // rejected as http_invalid_host_pattern earlier in the pipeline,
        // and that's correct — production sites don't use single-label hosts).
        $self_host_filter = static fn () => 'https://app.example.com';
        add_filter('home_url', $self_host_filter, 100);
        try {
            $this->expectException(ManifestError::class);
            $this->expectExceptionMessage('http_self_target_forbidden');
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['http'] = ['app.example.com'];
            Manifest::validate($arr);
        } finally {
            remove_filter('home_url', $self_host_filter, 100);
        }
    }

    public function test_permissions_http_rejects_invalid_chars(): void {
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('http_invalid_host_pattern');
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['http'] = ['api stripe.com'];  // space
        Manifest::validate($arr);
    }

    private function raw_with(array $overrides): array {
        return array_replace_recursive([
            'manifest_version' => 1,
            'id'               => 'sample-app',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict'],
        ], $overrides);
    }

    private function inline_raw_with(array $overrides): array {
        return $this->raw_with(array_replace_recursive([
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
            ],
            'runtime' => [
                'sandbox' => 'strict',
                'csp' => [
                    'script_src' => ['self'],
                    'style_src' => ['self'],
                    'img_src' => ['self', 'data:'],
                    'connect_src' => ['self'],
                ],
            ],
        ], $overrides));
    }

    private function valid_inline_manifest(): array {
        return [
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
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
    }

    private function valid_iframe_manifest(): array {
        return [
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ];
    }

    // --- AI / Abilities permissions --------------------------------------

    public function test_permissions_read_accepts_ai(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'ai';
        $arr['ai'] = ['max_tool_calls' => 3, 'timeout_seconds' => 45];
        $manifest = Manifest::validate($arr);
        $this->assertContains(\DSGo_Apps\Permission::Ai, $manifest->permissions_read);
        $this->assertSame(3, $manifest->ai_max_tool_calls);
        $this->assertSame(45, $manifest->ai_timeout_seconds);
    }

    public function test_permissions_read_accepts_abilities(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'abilities';
        $arr['abilities'] = ['consumes' => ['yoast/analyze-page-seo', 'woocommerce/list-*']];
        $manifest = Manifest::validate($arr);
        $this->assertContains(\DSGo_Apps\Permission::Abilities, $manifest->permissions_read);
        $this->assertSame(['yoast/analyze-page-seo', 'woocommerce/list-*'], $manifest->abilities_consumes);
    }

    public function test_ai_defaults_apply_when_options_omitted(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'ai';
        $manifest = Manifest::validate($arr);
        $this->assertSame(5,  $manifest->ai_max_tool_calls);
        $this->assertSame(60, $manifest->ai_timeout_seconds);
    }

    public function test_ai_max_tool_calls_out_of_range_rejected(): void {
        foreach ([-1, 11, 100] as $bad) {
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['read'][] = 'ai';
            $arr['ai'] = ['max_tool_calls' => $bad];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for ai.max_tool_calls=$bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/max_tool_calls/i', $e->getMessage());
            }
        }
    }

    public function test_ai_timeout_out_of_range_rejected(): void {
        foreach ([4, 121, 0] as $bad) {
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['read'][] = 'ai';
            $arr['ai'] = ['timeout_seconds' => $bad];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for ai.timeout_seconds=$bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/timeout_seconds/i', $e->getMessage());
            }
        }
    }

    public function test_ai_options_without_ai_permission_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['ai'] = ['max_tool_calls' => 3];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/ai_options_misplaced|ai/i');
        Manifest::validate($arr);
    }

    public function test_abilities_consumes_required_when_abilities_permission_present(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'abilities';
        // No abilities.consumes provided.
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_consumes_required|consumes/i');
        Manifest::validate($arr);
    }

    public function test_abilities_consumes_misplaced_without_permission_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['abilities'] = ['consumes' => ['yoast/x']];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_consumes_misplaced|abilities/i');
        Manifest::validate($arr);
    }

    public function test_abilities_consumes_pattern_validation(): void {
        $valid = [
            'yoast/analyze-page-seo',
            'yoast/*',
            'woocommerce/list-*',
            '9to5/foo',
        ];
        foreach ($valid as $pattern) {
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['read'][] = 'abilities';
            $arr['abilities'] = ['consumes' => [$pattern]];
            $manifest = Manifest::validate($arr);
            $this->assertSame([$pattern], $manifest->abilities_consumes, "pattern $pattern should be valid");
        }

        $invalid_no_namespace = ['*', '*/list'];
        foreach ($invalid_no_namespace as $pattern) {
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['read'][] = 'abilities';
            $arr['abilities'] = ['consumes' => [$pattern]];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for pattern: $pattern");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression(
                    '/abilities_pattern_no_namespace|abilities_pattern_invalid|namespace/i',
                    $e->getMessage(), $pattern,
                );
            }
        }

        $invalid_general = ['yoast/*-seo', 'yoast/foo_bar', 'yoast', 'Yoast/x'];
        foreach ($invalid_general as $pattern) {
            $arr = $this->valid_inline_manifest();
            $arr['permissions']['read'][] = 'abilities';
            $arr['abilities'] = ['consumes' => [$pattern]];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for pattern: $pattern");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/abilities_pattern|consumes/i', $e->getMessage(), $pattern);
            }
        }
    }

    public function test_abilities_consumes_too_many_rejected(): void {
        $patterns = [];
        for ($i = 0; $i < 33; $i++) { $patterns[] = "ns$i/foo"; }
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'abilities';
        $arr['abilities'] = ['consumes' => $patterns];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_consumes_too_many/i');
        Manifest::validate($arr);
    }

    public function test_abilities_consumes_duplicate_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'abilities';
        $arr['abilities'] = ['consumes' => ['yoast/x', 'yoast/x']];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_consumes_duplicate/i');
        Manifest::validate($arr);
    }

    public function test_abilities_consumes_empty_array_allowed(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'abilities';
        $arr['abilities'] = ['consumes' => []];
        $manifest = Manifest::validate($arr);
        $this->assertSame([], $manifest->abilities_consumes);
    }

    // --- Dynamic routes -------------------------------------------------

    public function test_param_path_with_valid_dataset_is_accepted(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = [
            'path' => '/customers/:id',
            'file' => 'customer.html',
            'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id'],
        ];
        $manifest = Manifest::validate($arr);
        $route = $manifest->routes[1];
        $this->assertSame('/customers/:id', $route['path']);
        $this->assertSame('id', $route['dataset']['id_field']);
        $this->assertSame('data/customers.json', $route['dataset']['source']);
    }

    public function test_param_path_without_dataset_is_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = ['path' => '/customers/:id', 'file' => 'customer.html'];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/dataset/');
        Manifest::validate($arr);
    }

    public function test_dataset_on_static_route_is_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = [
            'path' => '/about',
            'file' => 'about.html',
            'dataset' => ['source' => 'data/x.json', 'id_field' => 'id'],
        ];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/dataset/');
        Manifest::validate($arr);
    }

    public function test_multiple_params_in_path_are_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = [
            'path' => '/regions/:region/customers/:id',
            'file' => 'x.html',
            'dataset' => ['source' => 'data/x.json', 'id_field' => 'id'],
        ];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/route_path_multiple_params|multiple/i');
        Manifest::validate($arr);
    }

    public function test_param_must_be_complete_path_segment(): void {
        foreach (['/c-:id', '/customers/:id-foo', '/customers/x:id'] as $bad) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => $bad,
                'file' => 'x.html',
                'dataset' => ['source' => 'data/x.json', 'id_field' => 'id'],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for path: $bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/segment|param/i', $e->getMessage(), $bad);
            }
        }
    }

    public function test_param_name_pattern_is_enforced(): void {
        foreach ([':1id', ':ID', ':id-x', ':-id'] as $bad_param) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/customers/' . $bad_param,
                'file' => 'x.html',
                'dataset' => ['source' => 'data/x.json', 'id_field' => 'id'],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for param: $bad_param");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/param/i', $e->getMessage(), $bad_param);
            }
        }
    }

    public function test_dataset_source_must_be_relative_json(): void {
        foreach (['/abs.json', '../escape.json', 'data/foo.txt'] as $bad_source) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/customers/:id',
                'file' => 'x.html',
                'dataset' => ['source' => $bad_source, 'id_field' => 'id'],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for source: $bad_source");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/source|dataset/i', $e->getMessage(), $bad_source);
            }
        }
    }

    public function test_dataset_source_accepts_custom_scheme(): void {
        // The dsgo_apps_dataset_resolver filter is documented as a way to
        // register custom live sources (e.g. `edd:downloads`, `gf:forms`).
        // Manifest validation must accept any well-formed `<scheme>:<id>`
        // source so that custom-scheme apps can install at all.
        foreach (['edd:downloads', 'gf:forms', 'app:user-favorites', 'demo:fixed', 'mu_plugin:rows'] as $source) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/x/:key',
                'file' => 'x.html',
                'dataset' => ['source' => $source, 'id_field' => 'key'],
            ];
            try {
                $m = Manifest::validate($arr);
                $hit = null;
                foreach ($m->routes as $r) {
                    if (($r['dataset']['source'] ?? '') === $source) { $hit = $r; break; }
                }
                $this->assertNotNull($hit, "expected acceptance of $source");
                $this->assertSame('key', $hit['dataset']['id_field'], "id_field should round-trip for $source");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->fail("custom scheme rejected: $source ({$e->getMessage()})");
            }
        }
    }

    public function test_dataset_source_rejects_malformed_custom_scheme(): void {
        // No identifier after colon, leading slash on the id, or path traversal
        // → still treated as bad input and rejected (otherwise the validator
        // would accept things that look like file paths or escapes).
        foreach (['scheme:', ':lonely', 'wp:../escape', 'edd:..', 'edd:foo/../bar'] as $bad) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/x/:k',
                'file' => 'x.html',
                'dataset' => ['source' => $bad, 'id_field' => 'k'],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection of malformed source: $bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/source|dataset/i', $e->getMessage(), $bad);
            }
        }
    }

    public function test_dataset_id_field_unrestricted_for_custom_sources(): void {
        // Built-in live sources restrict id_field to slug|id (those are the
        // only fields the resolver guarantees are unique). Custom resolvers
        // control their own row shape — accept any valid identifier.
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = [
            'path' => '/x/:key',
            'file' => 'x.html',
            'dataset' => ['source' => 'edd:downloads', 'id_field' => 'product_code'],
        ];
        $m = Manifest::validate($arr);
        $hit = null;
        foreach ($m->routes as $r) {
            if (($r['dataset']['source'] ?? '') === 'edd:downloads') { $hit = $r; break; }
        }
        $this->assertNotNull($hit);
        $this->assertSame('product_code', $hit['dataset']['id_field']);
    }

    public function test_dataset_id_field_still_restricted_for_built_in_live_sources(): void {
        foreach (['wp:posts', 'wp:cpt:case_study', 'wc:products'] as $source) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/x/:key',
                'file' => 'x.html',
                'dataset' => ['source' => $source, 'id_field' => 'custom_field'],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection: $source with non-slug/id id_field");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/id_field|slug|id/i', $e->getMessage(), $source);
            }
        }
    }

    public function test_dataset_source_accepts_wc_products(): void {
        foreach (['slug', 'id'] as $id_field) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/shop/:slug',
                'file' => 'product.html',
                'dataset' => ['source' => 'wc:products', 'id_field' => $id_field],
            ];
            $m = Manifest::validate($arr);
            $shop = null;
            foreach ($m->routes as $r) {
                if ($r['path'] === '/shop/:slug') { $shop = $r; break; }
            }
            $this->assertNotNull($shop, "id_field=$id_field");
            $this->assertSame('wc:products', $shop['dataset']['source']);
            $this->assertSame($id_field, $shop['dataset']['id_field']);
        }
    }

    public function test_dataset_id_field_pattern_is_enforced(): void {
        foreach (['1id', 'foo.bar', 'foo-bar', ''] as $bad_id) {
            $arr = $this->valid_inline_manifest();
            $arr['routes'][] = [
                'path' => '/customers/:id',
                'file' => 'x.html',
                'dataset' => ['source' => 'data/x.json', 'id_field' => $bad_id],
            ];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for id_field: $bad_id");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/id_field|dataset/i', $e->getMessage(), $bad_id);
            }
        }
    }

    // --- abilities.publishes -------------------------------------------

    public function test_abilities_publishes_accepts_valid_entries(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [
            [
                'name' => 'sample/import-from-url',
                'label' => 'Import from URL',
                'description' => 'Imports a thing.',
                'category' => 'content',
                'input_schema' => ['type' => 'object'],
                'output_schema' => ['type' => 'object'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'timeout_seconds' => 45,
            ],
        ]];
        $manifest = Manifest::validate($arr);
        $this->assertCount(1, $manifest->abilities_publishes);
        $this->assertSame('sample/import-from-url', $manifest->abilities_publishes[0]['name']);
        $this->assertSame(45, $manifest->abilities_publishes[0]['timeout_seconds']);
        $this->assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true],
            $manifest->abilities_publishes[0]['annotations']);
    }

    public function test_abilities_publishes_default_timeout(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'sample/foo', 'label' => 'Foo', 'description' => 'd',
            'category' => 'content',
        ]]];
        $manifest = Manifest::validate($arr);
        $this->assertSame(30, $manifest->abilities_publishes[0]['timeout_seconds']);
    }

    public function test_abilities_publishes_accepted_for_inline_mode(): void {
        $arr = $this->valid_inline_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'sample/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content',
        ]]];
        $manifest = Manifest::validate($arr);
        $this->assertCount(1, $manifest->abilities_publishes);
        $this->assertSame('sample/foo', $manifest->abilities_publishes[0]['name']);
    }

    public function test_route_path_dsgo_host_is_reserved(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = ['path' => '/__dsgo-host', 'file' => 'host.html'];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/route_path_reserved|__dsgo-host/i');
        Manifest::validate($arr);
    }

    public function test_route_path_dsgo_host_with_trailing_slash_is_reserved(): void {
        $arr = $this->valid_inline_manifest();
        $arr['routes'][] = ['path' => '/__dsgo-host/', 'file' => 'host.html'];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/route_path_reserved|__dsgo-host/i');
        Manifest::validate($arr);
    }

    public function test_abilities_publishes_namespace_must_match_app_id(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'other/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content',
        ]]];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_publish_namespace_mismatch|namespace/i');
        Manifest::validate($arr);
    }

    public function test_abilities_publishes_name_pattern(): void {
        foreach (['Sample/foo', 'sample/Foo', 'sample/foo_bar', 'sample-only', 'sample/'] as $bad) {
            $arr = $this->valid_iframe_manifest();
            $arr['abilities'] = ['publishes' => [[
                'name' => $bad, 'label' => 'L', 'description' => 'd', 'category' => 'content',
            ]]];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for: $bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/abilities_publish|name|namespace/i', $e->getMessage(), $bad);
            }
        }
    }

    public function test_abilities_publishes_too_many_rejected(): void {
        $entries = [];
        for ($i = 0; $i < 9; $i++) {
            $entries[] = ['name' => "sample/x$i", 'label' => 'L', 'description' => 'd', 'category' => 'content'];
        }
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => $entries];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_publishes_too_many/i');
        Manifest::validate($arr);
    }

    public function test_abilities_publishes_duplicate_rejected(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [
            ['name' => 'sample/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content'],
            ['name' => 'sample/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content'],
        ]];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_publishes_duplicate/i');
        Manifest::validate($arr);
    }

    public function test_abilities_publishes_unknown_annotation_rejected(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'sample/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content',
            'annotations' => ['readonly' => true, 'unknown_key' => true],
        ]]];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_publish_unknown_annotation|annotation/i');
        Manifest::validate($arr);
    }

    public function test_abilities_publishes_timeout_out_of_range(): void {
        foreach ([4, 121, 0, -1] as $bad) {
            $arr = $this->valid_iframe_manifest();
            $arr['abilities'] = ['publishes' => [[
                'name' => 'sample/foo', 'label' => 'L', 'description' => 'd',
                'category' => 'content', 'timeout_seconds' => $bad,
            ]]];
            try {
                Manifest::validate($arr);
                $this->fail("expected rejection for timeout=$bad");
            } catch (\DSGo_Apps\ManifestError $e) {
                $this->assertMatchesRegularExpression('/timeout_seconds/i', $e->getMessage());
            }
        }
    }

    public function test_abilities_publishes_schema_must_be_object(): void {
        $arr = $this->valid_iframe_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'sample/foo', 'label' => 'L', 'description' => 'd', 'category' => 'content',
            'input_schema' => 'not-an-object',
        ]]];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/input_schema|schema/i');
        Manifest::validate($arr);
    }

    // --- Email permission ------------------------------------------------

    public function test_permissions_read_accepts_email(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = ['recipients' => ['admin']];
        $manifest = Manifest::validate($arr);
        $this->assertContains(Permission::Email, $manifest->permissions_read);
        $this->assertSame([\DSGo_Apps\EmailRecipient::Admin], $manifest->email_recipients);
    }

    public function test_email_block_required_when_email_permission_present(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/email_recipients_required|recipients/i');
        Manifest::validate($arr);
    }

    public function test_email_recipients_required_when_email_block_missing_recipients(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = [];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/email_recipients_required|recipients/i');
        Manifest::validate($arr);
    }

    public function test_email_block_without_email_permission_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['email'] = ['recipients' => ['admin']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/email_options_misplaced|email/i');
        Manifest::validate($arr);
    }

    public function test_email_recipients_must_be_non_empty(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = ['recipients' => []];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/non-empty|recipients/i');
        Manifest::validate($arr);
    }

    public function test_email_recipients_rejects_unknown_value(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = ['recipients' => ['admin', 'random@example.com']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/admin.*current_user|recipients/i');
        Manifest::validate($arr);
    }

    public function test_email_recipients_rejects_duplicates(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = ['recipients' => ['admin', 'admin']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/duplicate/i');
        Manifest::validate($arr);
    }

    public function test_email_to_array_round_trips(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'email';
        $arr['email'] = ['recipients' => ['admin', 'current_user']];
        $manifest = Manifest::validate($arr);
        $serialized = $manifest->to_array();
        $this->assertSame(['admin', 'current_user'], $serialized['email']['recipients']);
    }

    public function test_media_uploads_default_to_enabled(): void {
        $manifest = Manifest::validate($this->valid_inline_manifest());
        $this->assertTrue($manifest->media_uploads_enabled);
        // Default-on case: round-trip omits the `media` block entirely so
        // existing manifests stay byte-identical when re-serialized.
        $this->assertArrayNotHasKey('media', $manifest->to_array());
    }

    public function test_media_uploads_can_be_disabled_via_manifest(): void {
        $arr = $this->valid_inline_manifest();
        $arr['media'] = ['uploads' => false];
        $manifest = Manifest::validate($arr);
        $this->assertFalse($manifest->media_uploads_enabled);
        $this->assertSame(['uploads' => false], $manifest->to_array()['media']);
    }

    public function test_media_uploads_must_be_boolean(): void {
        $arr = $this->valid_inline_manifest();
        $arr['media'] = ['uploads' => 'no'];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/boolean/i');
        Manifest::validate($arr);
    }

    public function test_media_publish_defaults_to_empty_array_when_absent(): void {
        $manifest = Manifest::validate($this->valid_inline_manifest());
        $this->assertSame([], $manifest->media_publish_globs);
    }

    public function test_media_publish_accepts_array_of_glob_strings(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['og/*.png', 'screenshots/*']];
        $manifest = Manifest::validate($raw);
        $this->assertSame(['og/*.png', 'screenshots/*'], $manifest->media_publish_globs);
    }

    public function test_media_publish_rejects_non_array(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => 'og/*.png'];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish');
        Manifest::validate($raw);
    }

    public function test_media_publish_rejects_non_string_entry(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['ok.png', 42]];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish[1]');
        Manifest::validate($raw);
    }

    public function test_media_publish_rejects_empty_string(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish[0]');
        Manifest::validate($raw);
    }

    public function test_media_publish_rejects_path_escape(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['../etc/passwd']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish[0]');
        Manifest::validate($raw);
    }

    public function test_media_publish_rejects_absolute_path(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['/etc/hosts']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish[0]');
        Manifest::validate($raw);
    }

    public function test_media_publish_caps_at_32_patterns(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => array_fill(0, 33, '*.png')];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('media.publish');
        Manifest::validate($raw);
    }

    public function test_media_publish_round_trips_through_to_array(): void {
        $raw = $this->valid_inline_manifest();
        $raw['media'] = ['publish' => ['og/*.png']];
        $manifest = Manifest::validate($raw);
        $out = $manifest->to_array();
        $this->assertArrayHasKey('media', $out);
        $this->assertSame(['og/*.png'], $out['media']['publish']);
    }

    // --- Commerce permission --------------------------------------------------

    public function test_permission_enum_includes_commerce(): void {
        $this->assertSame('commerce', Permission::Commerce->value);
    }

    public function test_commerce_permission_requires_commerce_block(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/commerce_options_required|commerce/i');
        Manifest::validate($arr);
    }

    public function test_commerce_block_without_commerce_permission_rejected(): void {
        $arr = $this->valid_inline_manifest();
        $arr['commerce'] = ['providers' => ['woocommerce'], 'endpoints' => ['products']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/commerce_options_misplaced|commerce/i');
        Manifest::validate($arr);
    }

    public function test_commerce_providers_required(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['endpoints' => ['products']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/commerce_providers_required|providers/i');
        Manifest::validate($arr);
    }

    public function test_commerce_providers_rejects_unknown(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['providers' => ['shopify'], 'endpoints' => ['products']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/woocommerce|provider/i');
        Manifest::validate($arr);
    }

    public function test_commerce_endpoints_required(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['providers' => ['woocommerce']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/commerce_endpoints_required|endpoints/i');
        Manifest::validate($arr);
    }

    public function test_commerce_endpoints_rejects_unknown(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['providers' => ['woocommerce'], 'endpoints' => ['orders']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/products.*cart.*checkout|endpoints/i');
        Manifest::validate($arr);
    }

    public function test_commerce_endpoints_rejects_duplicates(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['providers' => ['woocommerce'], 'endpoints' => ['products', 'products']];
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessageMatches('/duplicate/i');
        Manifest::validate($arr);
    }

    public function test_commerce_to_array_round_trips(): void {
        $arr = $this->valid_inline_manifest();
        $arr['permissions']['read'][] = 'commerce';
        $arr['commerce'] = ['providers' => ['woocommerce'], 'endpoints' => ['products', 'cart', 'checkout']];
        $manifest = Manifest::validate($arr);
        $serialized = $manifest->to_array();
        $this->assertSame(['woocommerce'], $serialized['commerce']['providers']);
        $this->assertSame(['products', 'cart', 'checkout'], $serialized['commerce']['endpoints']);
    }
}
