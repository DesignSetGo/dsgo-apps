<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\InlineRenderer;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class InlineRendererDynamicRouteTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Open the dynamic_routes gate for all tests in this suite so existing
        // dataset and render tests exercise the live-source path without
        // hitting the Pro gate. Gate-closed behaviour is tested in
        // DataSourcesTest and InlineRendererTest.
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $en, string $feature): bool {
            return $feature === 'dynamic_routes' ? true : $en;
        }, 10, 2);
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        parent::tear_down();
    }

    public function test_resolve_route_literal_wins_over_dynamic(): void {
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/customers/list', 'file' => 'list.html'],
            ['path' => '/customers/:id',  'file' => 'customer.html',
             'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
        ]));
        $hit = InlineRenderer::resolve_route($manifest, '/customers/list');
        $this->assertNotNull($hit);
        [$route, $params] = $hit;
        $this->assertSame('/customers/list', $route['path']);
        $this->assertSame([], $params);
    }

    public function test_resolve_route_dynamic_captures_param(): void {
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/customers/:id', 'file' => 'customer.html',
             'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
        ]));
        $hit = InlineRenderer::resolve_route($manifest, '/customers/abc-123');
        $this->assertNotNull($hit);
        [$route, $params] = $hit;
        $this->assertSame('/customers/:id', $route['path']);
        $this->assertSame(['id' => 'abc-123'], $params);
    }

    public function test_resolve_route_dynamic_does_not_match_extra_segments(): void {
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/customers/:id', 'file' => 'customer.html',
             'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
        ]));
        $this->assertNull(InlineRenderer::resolve_route($manifest, '/customers/abc/extra'));
        $this->assertNull(InlineRenderer::resolve_route($manifest, '/customers/'));
    }

    public function test_resolve_route_dynamic_decodes_url_encoded_param(): void {
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/customers/:id', 'file' => 'customer.html',
             'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
        ]));
        $hit = InlineRenderer::resolve_route($manifest, '/customers/' . rawurlencode('a~b'));
        $this->assertNotNull($hit);
        [, $params] = $hit;
        $this->assertSame('a~b', $params['id']);
    }

    // --- substitute ------------------------------------------------------

    public function test_substitute_double_brace_html_escapes(): void {
        $out = InlineRenderer::substitute('<h1>{{name}}</h1>', ['name' => 'Acme & Co']);
        $this->assertSame('<h1>Acme &amp; Co</h1>', $out);
    }

    public function test_substitute_triple_brace_emits_raw(): void {
        $out = InlineRenderer::substitute('<div>{{{name}}}</div>', ['name' => '<strong>x</strong>']);
        $this->assertSame('<div><strong>x</strong></div>', $out);
    }

    public function test_substitute_dot_notation_walks_objects(): void {
        $out = InlineRenderer::substitute(
            '<p>{{customer.address.city}}</p>',
            ['customer' => ['address' => ['city' => 'Brooklyn']]],
        );
        $this->assertSame('<p>Brooklyn</p>', $out);
    }

    public function test_substitute_missing_field_renders_empty(): void {
        $out = InlineRenderer::substitute('<p>{{missing.path}}</p>', ['present' => 'yes']);
        $this->assertSame('<p></p>', $out);
    }

    public function test_substitute_value_types(): void {
        $entry = [
            'n_int'   => 42,
            'n_float' => 1.5,
            'b_true'  => true,
            'b_false' => false,
            'n_null'  => null,
            'arr'     => [1, 2, 3],
            'obj'     => ['x' => 1],
        ];
        $tpl = '[{{n_int}}|{{n_float}}|{{b_true}}|{{b_false}}|{{n_null}}|{{arr}}|{{obj}}]';
        $this->assertSame('[42|1.5|true|false||[object]|[object]]', InlineRenderer::substitute($tpl, $entry));
    }

    public function test_substitute_does_not_run_inside_script_blocks(): void {
        $tpl = '<script>const x = "{{name}}";</script><p>{{name}}</p>';
        $out = InlineRenderer::substitute($tpl, ['name' => 'Alice']);
        // <script> body must be byte-identical to the input.
        $this->assertStringContainsString('<script>const x = "{{name}}";</script>', $out);
        // <p> body must have substituted (escaped).
        $this->assertStringContainsString('<p>Alice</p>', $out);
    }

    public function test_substitute_does_not_run_inside_style_blocks(): void {
        $tpl = '<style>.x { color: "{{color}}"; }</style><p>{{color}}</p>';
        $out = InlineRenderer::substitute($tpl, ['color' => 'red']);
        $this->assertStringContainsString('<style>.x { color: "{{color}}"; }</style>', $out);
        $this->assertStringContainsString('<p>red</p>', $out);
    }

    public function test_substitute_handles_script_with_attributes(): void {
        $tpl = '<script type="application/json">{ "name": "{{name}}" }</script>';
        $out = InlineRenderer::substitute($tpl, ['name' => 'Alice']);
        $this->assertSame($tpl, $out);
    }

    public function test_substitute_treats_literal_double_brace_in_raw_value_as_text(): void {
        // Dataset entry contains literal "{{ literal }}" — the second pass must
        // NOT re-substitute it, since {{{}}} ran first and the value was
        // sentinel-replaced.
        $tpl = '<p>{{{html}}}</p>';
        $out = InlineRenderer::substitute($tpl, ['html' => '<em>{{ literal }}</em>']);
        $this->assertSame('<p><em>{{ literal }}</em></p>', $out);
    }

    public function test_substitute_leaves_empty_braces_as_literal(): void {
        $this->assertSame('<p>{{}}</p>',    InlineRenderer::substitute('<p>{{}}</p>',    []));
        $this->assertSame('<p>{{ }}</p>',  InlineRenderer::substitute('<p>{{ }}</p>',  []));
        // Leading/trailing/double dots are not valid path syntax — left literal.
        $this->assertSame('<p>{{.foo}}</p>', InlineRenderer::substitute('<p>{{.foo}}</p>', ['foo' => 'x']));
        $this->assertSame('<p>{{foo.}}</p>', InlineRenderer::substitute('<p>{{foo.}}</p>', ['foo' => 'x']));
        $this->assertSame('<p>{{a..b}}</p>', InlineRenderer::substitute('<p>{{a..b}}</p>', []));
    }

    // --- cache version + dataset load -----------------------------------

    public function test_cache_version_lazily_generates(): void {
        delete_option('dsgo_app_cache_version_test-app');
        $v1 = InlineRenderer::cache_version('test-app');
        $this->assertNotSame('', $v1);
        $v2 = InlineRenderer::cache_version('test-app');
        $this->assertSame($v1, $v2, 'second read returns the same value');
        delete_option('dsgo_app_cache_version_test-app');
    }

    public function test_bump_cache_version_changes_value(): void {
        delete_option('dsgo_app_cache_version_test-app');
        $v1 = InlineRenderer::cache_version('test-app');
        InlineRenderer::bump_cache_version('test-app');
        $v2 = InlineRenderer::cache_version('test-app');
        $this->assertNotSame($v1, $v2);
        delete_option('dsgo_app_cache_version_test-app');
    }

    public function test_load_dataset_reads_and_caches(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-load-' . uniqid();
        mkdir($bundle . '/data', 0755, true);
        file_put_contents($bundle . '/data/items.json', json_encode([['id' => 'a'], ['id' => 'b']]));
        $route = ['path' => '/items/:id', 'file' => 'item.html',
                  'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']];

        $first  = InlineRenderer::load_dataset($bundle, 'demo', $route);
        $this->assertCount(2, $first);
        $this->assertSame('a', $first[0]['id']);

        // Mutate the on-disk file. A cache hit means we still see the old data.
        file_put_contents($bundle . '/data/items.json', json_encode([['id' => 'c']]));
        $second = InlineRenderer::load_dataset($bundle, 'demo', $route);
        $this->assertSame($first, $second, 'second read served from transient');

        // Bumping the version invalidates everything via key change.
        InlineRenderer::bump_cache_version('demo');
        $third = InlineRenderer::load_dataset($bundle, 'demo', $route);
        $this->assertCount(1, $third);
        $this->assertSame('c', $third[0]['id']);
        delete_option('dsgo_app_cache_version_demo');
    }

    public function test_load_dataset_resolves_wp_posts_live_source(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-live-' . uniqid();
        mkdir($bundle, 0755, true);
        self::factory()->post->create(['post_title' => 'Live One', 'post_name' => 'live-one', 'post_status' => 'publish']);

        $route = ['path' => '/blog/:slug', 'file' => 'post.html',
                  'dataset' => ['source' => 'wp:posts', 'id_field' => 'slug']];

        $entries = InlineRenderer::load_dataset($bundle, 'demo-live', $route);
        $this->assertNotEmpty($entries, 'live wp:posts source must return entries');
        $slugs = array_map(static fn ($e) => $e['slug'] ?? null, $entries);
        $this->assertContains('live-one', $slugs);
        delete_option('dsgo_app_cache_version_demo-live');
    }

    public function test_load_dataset_segregates_cache_by_resolver_context(): void {
        // A custom resolver that depends on per-request state (mocked here
        // by a counter) must not leak rows between requests with different
        // cache-key-extra strings. The dsgo_apps_dataset_cache_key_extra
        // filter is the public hook for that namespacing.
        $bundle = sys_get_temp_dir() . '/dsgo-ctx-' . uniqid();
        mkdir($bundle, 0755, true);
        $route = ['path' => '/x/:k', 'file' => 'x.html',
                  'dataset' => ['source' => 'app:per_user', 'id_field' => 'k']];
        $current = 'alice';
        add_filter('dsgo_apps_dataset_resolver', function ($resolver, string $source) use (&$current) {
            if ($source !== 'app:per_user') return $resolver;
            return static fn () => [['k' => $current, 'name' => $current]];
        }, 10, 2);
        add_filter('dsgo_apps_dataset_cache_key_extra', function ($extra, string $source) use (&$current) {
            return $source === 'app:per_user' ? "user:{$current}" : $extra;
        }, 10, 2);

        try {
            $alice = InlineRenderer::load_dataset($bundle, 'ctx-app', $route);
            $this->assertSame('alice', $alice[0]['k']);

            $current = 'bob';
            $bob = InlineRenderer::load_dataset($bundle, 'ctx-app', $route);
            $this->assertSame('bob', $bob[0]['k'], 'switching cache-key-extra must miss the alice cache');

            // Reverting to alice must hit the cached alice rows, not bob's.
            $current = 'alice';
            $alice2 = InlineRenderer::load_dataset($bundle, 'ctx-app', $route);
            $this->assertSame('alice', $alice2[0]['k']);
        } finally {
            remove_all_filters('dsgo_apps_dataset_resolver');
            remove_all_filters('dsgo_apps_dataset_cache_key_extra');
            delete_option('dsgo_app_cache_version_ctx-app');
        }
    }

    public function test_load_dataset_skips_cache_when_ttl_is_zero(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-noc-' . uniqid();
        mkdir($bundle, 0755, true);
        $route = ['path' => '/x/:k', 'file' => 'x.html',
                  'dataset' => ['source' => 'app:realtime', 'id_field' => 'k']];
        $calls = 0;
        add_filter('dsgo_apps_dataset_resolver', function ($resolver, string $source) use (&$calls) {
            if ($source !== 'app:realtime') return $resolver;
            return static function () use (&$calls) {
                $calls++;
                return [['k' => 'r' . $calls]];
            };
        }, 10, 2);
        add_filter('dsgo_apps_dataset_cache_ttl', function ($ttl, string $source) {
            return $source === 'app:realtime' ? 0 : $ttl;
        }, 10, 2);

        try {
            $a = InlineRenderer::load_dataset($bundle, 'noc-app', $route);
            $b = InlineRenderer::load_dataset($bundle, 'noc-app', $route);
            $this->assertSame('r1', $a[0]['k']);
            $this->assertSame('r2', $b[0]['k']);
            $this->assertSame(2, $calls, 'resolver must be invoked on every call when ttl is 0');
        } finally {
            remove_all_filters('dsgo_apps_dataset_resolver');
            remove_all_filters('dsgo_apps_dataset_cache_ttl');
            delete_option('dsgo_app_cache_version_noc-app');
        }
    }

    public function test_load_dataset_returns_empty_for_unknown_live_scheme(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-unknown-' . uniqid();
        mkdir($bundle, 0755, true);
        $route = ['path' => '/x/:id', 'file' => 'x.html',
                  'dataset' => ['source' => 'mystery:thing', 'id_field' => 'slug']];
        $this->assertSame([], InlineRenderer::load_dataset($bundle, 'demo-unknown', $route));
        delete_option('dsgo_app_cache_version_demo-unknown');
    }

    public function test_find_entry_matches_id_field_string_or_int(): void {
        $entries = [
            ['id' => 'alice', 'name' => 'Alice'],
            ['id' => 42, 'name' => 'Forty-Two'],
        ];
        $this->assertSame($entries[0], InlineRenderer::find_entry($entries, 'id', 'alice'));
        $this->assertSame($entries[1], InlineRenderer::find_entry($entries, 'id', '42'));
        $this->assertNull(InlineRenderer::find_entry($entries, 'id', 'bob'));
    }

    // --- render_dynamic_route ------------------------------------------

    public function test_render_dynamic_route_substitutes_entry_into_template(): void {
        $bundle = $this->build_bundle_with_dataset('alpha', 'beta');
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/items/:id', 'file' => 'item.html',
             'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']],
        ]));
        $route = $manifest->routes[1];
        $context = ['mode' => 'inline', 'appId' => $manifest->id, 'routePath' => $route['path'],
                    'routeParams' => (object)['id' => 'alpha'], 'locale' => 'en-US', 'theme' => 'light'];

        $output = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'alpha'], $context, 'NONCE-D');
        $this->assertNotNull($output);
        $this->assertStringContainsString('Item: Alpha', $output);
        $this->assertStringContainsString('id="dsgo-context"', $output);
        $this->assertStringContainsString('NONCE-D', $output);
        InlineRenderer::bump_cache_version($manifest->id);
        delete_option('dsgo_app_cache_version_' . $manifest->id);
    }

    public function test_render_dynamic_route_returns_null_for_unknown_param(): void {
        $bundle = $this->build_bundle_with_dataset('alpha', 'beta');
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/items/:id', 'file' => 'item.html',
             'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']],
        ]));
        $route = $manifest->routes[1];
        $context = ['mode' => 'inline', 'appId' => $manifest->id, 'routePath' => $route['path'],
                    'routeParams' => (object)['id' => 'nope'], 'locale' => 'en-US', 'theme' => 'light'];

        $output = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'nope'], $context, 'N');
        $this->assertNull($output);
        delete_option('dsgo_app_cache_version_' . $manifest->id);
    }

    public function test_render_dynamic_route_caches_rendered_output(): void {
        $bundle = $this->build_bundle_with_dataset('alpha', 'beta');
        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/items/:id', 'file' => 'item.html',
             'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']],
        ]));
        $route = $manifest->routes[1];
        $context = ['mode' => 'inline', 'appId' => $manifest->id, 'routePath' => $route['path'],
                    'routeParams' => (object)['id' => 'alpha'], 'locale' => 'en-US', 'theme' => 'light'];

        $first  = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'alpha'], $context, 'NONCE-A');
        // Mutate the template; cache hit must still serve the old render.
        file_put_contents($bundle . '/item.html', '<!doctype html><html><body><h1>CHANGED</h1></body></html>');
        $second = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'alpha'], $context, 'NONCE-B');
        $this->assertSame($first, $second);

        // After bumping the version both the dataset and render caches miss.
        InlineRenderer::bump_cache_version($manifest->id);
        $third = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'alpha'], $context, 'NONCE-C');
        $this->assertStringContainsString('CHANGED', $third);
        delete_option('dsgo_app_cache_version_' . $manifest->id);
    }

    public function test_render_dynamic_route_does_not_inject_into_script_block(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-script-' . uniqid();
        mkdir($bundle . '/data', 0755, true);
        file_put_contents($bundle . '/index.html', '<!doctype html><html><body><h1>root</h1></body></html>');
        file_put_contents($bundle . '/item.html',
            '<!doctype html><html><body>'
            . '<script>const NAME = "{{name}}";</script>'
            . '<h1>{{name}}</h1>'
            . '</body></html>',
        );
        file_put_contents($bundle . '/data/items.json', json_encode([['id' => 'a', 'name' => 'Alice"];alert(1);//']]));

        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/items/:id', 'file' => 'item.html',
             'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']],
        ]));
        $route = $manifest->routes[1];
        $context = ['mode' => 'inline', 'appId' => $manifest->id, 'routePath' => $route['path'],
                    'routeParams' => (object)['id' => 'a'], 'locale' => 'en-US', 'theme' => 'light'];

        $output = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'a'], $context, 'N');
        $this->assertNotNull($output);
        // Critical XSS-floor assertion: the literal `{{name}}` survives inside <script>;
        // the dataset value never reaches the JS-string context.
        $this->assertStringContainsString('const NAME = "{{name}}"', $output);
        // <h1> body got the escaped substitution.
        $this->assertStringContainsString('Alice&quot;];alert(1);//', $output);
        delete_option('dsgo_app_cache_version_' . $manifest->id);
    }

    private function build_bundle_with_dataset(string ...$ids): string {
        $bundle = sys_get_temp_dir() . '/dsgo-rdr-' . uniqid();
        mkdir($bundle . '/data', 0755, true);
        file_put_contents($bundle . '/index.html', '<!doctype html><html><body><h1>root</h1></body></html>');
        file_put_contents($bundle . '/item.html',
            '<!doctype html><html><body><h1>Item: {{name}}</h1></body></html>');
        $entries = [];
        foreach ($ids as $id) {
            $entries[] = ['id' => $id, 'name' => ucfirst($id)];
        }
        file_put_contents($bundle . '/data/items.json', json_encode($entries));
        return $bundle;
    }

    public function test_dispatch_emits_404_for_unmatched_param(): void {
        // We can't easily test stream_route directly because it calls exit/echo,
        // but we can verify render_dynamic_route returns null which triggers the
        // 404 path. The exit-and-emit behavior of emit_404 is covered indirectly
        // by code review (it's a thin wrapper around status_header + render_route).
        $bundle = sys_get_temp_dir() . '/dsgo-404-' . uniqid();
        mkdir($bundle . '/data', 0755, true);
        file_put_contents($bundle . '/index.html', '<!doctype html><html><body>x</body></html>');
        file_put_contents($bundle . '/item.html', '<h1>{{name}}</h1>');
        file_put_contents($bundle . '/data/items.json', json_encode([['id' => 'a', 'name' => 'A']]));

        $manifest = Manifest::validate($this->manifest_with_dynamic([
            ['path' => '/items/:id', 'file' => 'item.html',
             'dataset' => ['source' => 'data/items.json', 'id_field' => 'id']],
        ]));
        $route = $manifest->routes[1];
        $context = ['mode' => 'inline', 'appId' => $manifest->id, 'routePath' => $route['path'],
                    'routeParams' => (object)['id' => 'missing'], 'locale' => 'en-US', 'theme' => 'light'];
        $output = InlineRenderer::render_dynamic_route($bundle, $manifest, $route, ['id' => 'missing'], $context, 'N');
        $this->assertNull($output, 'unmatched :param must yield null so caller can emit a 404');
        delete_option('dsgo_app_cache_version_' . $manifest->id);
    }

    private function manifest_with_dynamic(array $extra_routes): array {
        return [
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => array_merge(
                [['path' => '/', 'file' => 'index.html']],
                $extra_routes,
            ),
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ];
    }
}
