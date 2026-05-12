<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\DataSources;
use WP_UnitTestCase;

class DataSourcesTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // Open the dynamic_routes gate for all tests in this suite. Tests that
        // specifically verify gate-closed behaviour call remove_all_filters() first.
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $en, string $feature): bool {
            return $feature === 'dynamic_routes' ? true : $en;
        }, 10, 2);
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_dataset_resolver');
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        parent::tear_down();
    }

    // --- ProFeatureGate (dynamic_routes) -----------------------------------

    public function test_resolve_returns_feature_inactive_when_gate_closed(): void {
        // Gate is closed by default (no filter registered).
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $result = DataSources::resolve('wp:posts');
        $this->assertIsArray($result);
        $this->assertSame('feature_inactive', $result['error'] ?? null);
        $this->assertSame('dynamic_routes', $result['feature'] ?? null);
    }

    public function test_resolve_returns_feature_inactive_for_all_live_sources_when_gate_closed(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        foreach (['wp:posts', 'wp:pages', 'wp:cpt:project', 'wc:products'] as $source) {
            $result = DataSources::resolve($source);
            $this->assertIsArray($result, "source={$source}");
            $this->assertSame('feature_inactive', $result['error'] ?? null, "source={$source}");
        }
    }

    public function test_resolve_returns_null_for_unknown_source_even_when_gate_closed(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        $this->assertNull(DataSources::resolve('mystery:thing'));
        $this->assertNull(DataSources::resolve('data/items.json'));
    }

    // --- wp:posts -----------------------------------------------------------

    public function test_wp_posts_returns_published_posts_with_shaped_fields(): void {
        $a = self::factory()->post->create(['post_title' => 'Hello World', 'post_name' => 'hello-world', 'post_status' => 'publish']);
        $b = self::factory()->post->create(['post_title' => 'Second',      'post_name' => 'second',      'post_status' => 'publish']);
        self::factory()->post->create(['post_title' => 'Draft',         'post_status' => 'draft']);

        $entries = DataSources::resolve('wp:posts');
        $this->assertIsArray($entries);
        $slugs = array_map(static fn ($e) => $e['slug'], $entries);
        $this->assertContains('hello-world', $slugs);
        $this->assertContains('second', $slugs);
        $this->assertCount(2, $entries, 'drafts excluded');

        $hello = null;
        foreach ($entries as $e) {
            if ($e['slug'] === 'hello-world') { $hello = $e; break; }
        }
        $this->assertNotNull($hello);
        $this->assertSame($a, $hello['id']);
        $this->assertSame('Hello World', $hello['title']['rendered']);
        $this->assertArrayHasKey('content', $hello);
        $this->assertArrayHasKey('rendered', $hello['content']);
        $this->assertArrayHasKey('date', $hello);
        $this->assertArrayHasKey('author_name', $hello);
        $this->assertArrayHasKey('featured_media_url', $hello);
    }

    public function test_wp_posts_excludes_revisions_and_autosaves(): void {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        wp_save_post_revision($post_id);

        $entries = DataSources::resolve('wp:posts');
        $ids = array_map(static fn ($e) => $e['id'], $entries);
        foreach ($ids as $id) {
            $this->assertSame('post', get_post_type($id));
        }
    }

    // --- wp:pages -----------------------------------------------------------

    public function test_wp_pages_resolves_pages(): void {
        self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About', 'post_name' => 'about', 'post_status' => 'publish']);
        self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact', 'post_status' => 'publish']);

        $entries = DataSources::resolve('wp:pages');
        $slugs = array_map(static fn ($e) => $e['slug'], $entries);
        $this->assertContains('about', $slugs);
        $this->assertContains('contact', $slugs);
    }

    // --- wp:cpt:<slug> ------------------------------------------------------

    public function test_wp_cpt_resolves_arbitrary_post_type(): void {
        register_post_type('case_study', ['public' => true, 'show_in_rest' => true]);
        try {
            self::factory()->post->create(['post_type' => 'case_study', 'post_title' => 'Acme', 'post_name' => 'acme', 'post_status' => 'publish']);
            $entries = DataSources::resolve('wp:cpt:case_study');
            $this->assertCount(1, $entries);
            $this->assertSame('acme', $entries[0]['slug']);
            $this->assertSame('Acme', $entries[0]['title']['rendered']);
        } finally {
            unregister_post_type('case_study');
        }
    }

    public function test_wp_cpt_returns_empty_for_unregistered_post_type(): void {
        $this->assertSame([], DataSources::resolve('wp:cpt:nonexistent_type'));
    }

    // --- wc:products --------------------------------------------------------

    public function test_wc_products_returns_empty_when_woocommerce_unavailable(): void {
        if (function_exists('wc_get_products')) {
            $this->markTestSkipped('WooCommerce active in test env; this branch is exercised only without WC.');
        }
        $this->assertSame([], DataSources::resolve('wc:products'));
    }

    public function test_wc_products_returns_shaped_products(): void {
        if (!class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WooCommerce not loaded; wc:products resolver requires WC.');
        }
        $product = new \WC_Product_Simple();
        $product->set_name('Test Mug');
        $product->set_slug('test-mug');
        $product->set_status('publish');
        $product->set_regular_price('12.00');
        $product->set_price('12.00');
        $product->set_short_description('A small ceramic mug.');
        $product->set_description('Microwave-safe.');
        $product->save();
        try {
            $entries = DataSources::resolve('wc:products');
            $match = null;
            foreach ($entries as $e) {
                if ($e['slug'] === 'test-mug') { $match = $e; break; }
            }
            $this->assertNotNull($match, 'created product should appear');
            $this->assertSame('Test Mug', $match['name']);
            $this->assertSame('12.00', $match['price_amount']);
            $this->assertArrayHasKey('price', $match);
            $this->assertArrayHasKey('regular_price', $match);
            $this->assertArrayHasKey('sale_price', $match);
            $this->assertArrayHasKey('on_sale', $match);
            $this->assertArrayHasKey('is_in_stock', $match);
            $this->assertArrayHasKey('is_purchasable', $match);
            $this->assertArrayHasKey('sku', $match);
            $this->assertArrayHasKey('featured_media_url', $match);
            $this->assertArrayHasKey('add_to_cart_url', $match);
        } finally {
            $product->delete(true);
        }
    }

    // --- unknown source -----------------------------------------------------

    public function test_resolve_returns_null_for_unknown_scheme(): void {
        $this->assertNull(DataSources::resolve('mystery:thing'));
    }

    public function test_resolve_returns_null_for_non_scheme_string(): void {
        $this->assertNull(DataSources::resolve('data/items.json'));
    }

    // --- filter extensibility ----------------------------------------------

    public function test_filter_can_register_custom_resolver(): void {
        add_filter('dsgo_apps_dataset_resolver', static function ($resolver, string $source) {
            if ($source === 'demo:fixed') {
                return static fn () => [['id' => 'one', 'name' => 'One']];
            }
            return $resolver;
        }, 10, 2);

        $entries = DataSources::resolve('demo:fixed');
        $this->assertSame([['id' => 'one', 'name' => 'One']], $entries);
    }

    public function test_filter_can_override_built_in_resolver(): void {
        add_filter('dsgo_apps_dataset_resolver', static function ($resolver, string $source) {
            if ($source === 'wp:posts') {
                return static fn () => [['id' => 999, 'slug' => 'override', 'title' => ['rendered' => 'Override']]];
            }
            return $resolver;
        }, 10, 2);

        $entries = DataSources::resolve('wp:posts');
        $this->assertCount(1, $entries);
        $this->assertSame('override', $entries[0]['slug']);
    }
}
