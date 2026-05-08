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

    public function test_abilities_publishes_iframe_mode_only(): void {
        $arr = $this->valid_inline_manifest();
        $arr['abilities'] = ['publishes' => [[
            'name' => 'sample/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content',
        ]]];
        $this->expectException(\DSGo_Apps\ManifestError::class);
        $this->expectExceptionMessageMatches('/abilities_publishes_iframe_only|iframe/i');
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
}
