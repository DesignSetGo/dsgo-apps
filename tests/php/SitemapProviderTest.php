<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\SitemapProvider;
use WP_UnitTestCase;

class SitemapProviderTest extends WP_UnitTestCase {

    public function test_returns_url_per_route_for_inline_apps(): void {
        $provider = new SitemapProvider();
        $urls = $provider->build_urls_for_app('sample', [
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/about', 'file' => 'about.html'],
            ],
        ], '2026-05-07T00:00:00Z');

        $this->assertCount(2, $urls);
        $this->assertSame(home_url('/apps/sample/'), $urls[0]['loc']);
        $this->assertSame(home_url('/apps/sample/about'), $urls[1]['loc']);
        $this->assertSame('2026-05-07T00:00:00Z', $urls[0]['lastmod']);
    }

    public function test_uses_configured_url_prefix(): void {
        update_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX, 'mini');
        try {
            $provider = new SitemapProvider();
            $urls = $provider->build_urls_for_app('sample', [
                'isolation' => 'inline',
                'routes' => [
                    ['path' => '/', 'file' => 'index.html'],
                    ['path' => '/about', 'file' => 'about.html'],
                ],
            ], '2026-05-07T00:00:00Z');

            $this->assertSame(home_url('/mini/sample/'), $urls[0]['loc']);
            $this->assertSame(home_url('/mini/sample/about'), $urls[1]['loc']);
        } finally {
            delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
        }
    }

    public function test_root_mount_emits_bare_paths(): void {
        $provider = new SitemapProvider();
        $urls = $provider->build_urls_for_app('site-app', [
            'isolation' => 'inline',
            'mount'     => ['mode' => 'root'],
            'routes'    => [
                ['path' => '/',       'file' => 'index.html'],
                ['path' => '/about',  'file' => 'about.html'],
            ],
        ], '2026-05-07T00:00:00Z');

        $this->assertCount(2, $urls);
        $this->assertSame(home_url('/'), $urls[0]['loc']);
        $this->assertSame(home_url('/about'), $urls[1]['loc']);
    }

    public function test_omits_iframe_apps(): void {
        $provider = new SitemapProvider();
        $urls = $provider->build_urls_for_app('legacy', [
            'isolation' => 'iframe',
        ], '2026-05-07T00:00:00Z');
        $this->assertCount(0, $urls);
    }

    /**
     * WP core's sitemap rewrite regex `^wp-sitemap-([a-z]+?)-(\d+?)\.xml$`
     * only matches `[a-z]+` for the provider name. A name with digits or
     * underscores is advertised in the sitemap index but unreachable, so
     * pin the provider name to all-lowercase letters.
     */
    public function test_provider_name_matches_wp_sitemap_url_regex(): void {
        $provider = new SitemapProvider();
        $ref = new \ReflectionProperty(\WP_Sitemaps_Provider::class, 'name');
        $ref->setAccessible(true);
        $this->assertMatchesRegularExpression('/^[a-z]+$/', $ref->getValue($provider));
    }

    public function test_dynamic_route_emits_one_url_per_dataset_entry(): void {
        $bundle = sys_get_temp_dir() . '/dsgo-sm-' . uniqid();
        mkdir($bundle . '/data', 0755, true);
        file_put_contents($bundle . '/index.html', '<!doctype html><html><body>x</body></html>');
        file_put_contents($bundle . '/customer.html', '<!doctype html><html><body>x</body></html>');
        file_put_contents($bundle . '/data/customers.json', json_encode([
            ['id' => 'alice'], ['id' => 'bob'], ['id' => 'a~b'],
        ]));

        // Make load_dataset find the file by stamping the cache version + bundle path.
        \DSGo_Apps\InlineRenderer::bump_cache_version('demo');

        $manifest_arr = [
            'manifest_version' => 1, 'id' => 'demo', 'name' => 'D', 'version' => '0.1.0', 'entry' => 'index.html',
            'isolation' => 'inline',
            'routes' => [
                ['path' => '/', 'file' => 'index.html'],
                ['path' => '/customers/:id', 'file' => 'customer.html',
                 'dataset' => ['source' => 'data/customers.json', 'id_field' => 'id']],
            ],
            'mount' => ['mode' => 'prefixed'],
        ];

        $provider = new \DSGo_Apps\SitemapProvider();
        $urls = $provider->build_urls_for_app('demo', $manifest_arr, '2026-05-07T00:00:00+00:00', $bundle);

        $locs = array_column($urls, 'loc');
        $this->assertContains(home_url('/apps/demo/'), $locs);
        $this->assertContains(home_url('/apps/demo/customers/alice'), $locs);
        $this->assertContains(home_url('/apps/demo/customers/bob'), $locs);
        $this->assertContains(home_url('/apps/demo/customers/a~b'), $locs);

        delete_option('dsgo_app_cache_version_demo');
    }
}
