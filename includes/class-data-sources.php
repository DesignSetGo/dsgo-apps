<?php
/**
 * Live dataset resolvers — turn `wp:posts`, `wp:pages`, `wp:cpt:<slug>`,
 * and `wc:products` source strings into the same array-of-rows shape that
 * a bundle-shipped JSON dataset produces.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class DataSources {

    /**
     * Resolve a dataset source string to an array of rows. Returns null when
     * the string isn't a recognized live-source scheme (the caller falls back
     * to bundle-file resolution). Returns [] when the scheme is recognized
     * but the host can't satisfy it (e.g. wc:products without WooCommerce).
     * Returns ['error' => 'feature_inactive', 'feature' => 'dynamic_routes']
     * when a resolver exists but the Pro gate is closed.
     *
     * The optional Manifest is used to attach a `content_styles` sibling to
     * post rows when the manifest opts in via `content.blockStyles` /
     * `content.themeStyles`. Pass null when the caller doesn't need styles
     * (e.g. sitemap URL generation).
     *
     * @return array<int|string, mixed>|null
     */
    public static function resolve(string $source, ?Manifest $manifest = null): ?array {
        $resolver = self::built_in_resolver($source, $manifest);

        /**
         * Filter the resolver callable for a given source string. Return a
         * callable to take over (built-in or otherwise); return null to opt
         * out and let the caller treat the source as a bundle file.
         *
         * @param callable|null $resolver
         * @param string        $source
         */
        $resolver = apply_filters('dsgo_apps_dataset_resolver', $resolver, $source);

        if (!is_callable($resolver)) {
            return null;
        }

        // ProFeatureGate is the only enforcement point for dynamic-route
        // resolution. Free sites accept the manifest field but the resolver
        // refuses to materialize live data. The check is deferred until after
        // resolver discovery so that sources with no resolver (unrecognized
        // schemes, bundle-relative JSON paths) still return null and fall
        // through to the bundle-file loader in InlineRenderer::load_dataset.
        if (!ProFeatureGate::is_enabled('dynamic_routes')) {
            return ['error' => 'feature_inactive', 'feature' => 'dynamic_routes'];
        }

        $rows = $resolver();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return callable():array<int, array<string, mixed>>|null
     */
    private static function built_in_resolver(string $source, ?Manifest $manifest = null): ?callable {
        if ($source === 'wp:posts') {
            return static fn (): array => self::resolve_posts('post', $manifest);
        }
        if ($source === 'wp:pages') {
            return static fn (): array => self::resolve_posts('page', $manifest);
        }
        if (preg_match('/^wp:cpt:([a-z][a-z0-9_-]*)$/', $source, $m) === 1) {
            $type = $m[1];
            return static fn (): array => self::resolve_posts($type, $manifest);
        }
        if ($source === 'wc:products') {
            return static fn (): array => self::resolve_wc_products();
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolve_posts(string $post_type, ?Manifest $manifest = null): array {
        if (!post_type_exists($post_type)) {
            return [];
        }
        // Cap at 500 to mirror the bundle-file dataset cap in class-bundle.php.
        $q = new \WP_Query([
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => 500,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
        ]);
        $rows = [];
        foreach ($q->posts as $p) {
            if (!$p instanceof \WP_Post) continue;
            $rows[] = self::shape_post($p, $manifest);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape_post(\WP_Post $p, ?Manifest $manifest = null): array {
        $thumb_id = (int) get_post_thumbnail_id($p);
        $thumb_url = '';
        if ($thumb_id > 0) {
            $src = wp_get_attachment_image_src($thumb_id, 'large');
            if (is_array($src) && isset($src[0])) {
                $thumb_url = (string) $src[0];
            }
        }
        $row = [
            'id'                 => (int) $p->ID,
            'slug'               => (string) $p->post_name,
            'date'               => mysql2date('M j, Y', $p->post_date),
            'date_iso'           => mysql2date('c', $p->post_date),
            'modified_iso'       => mysql2date('c', $p->post_modified),
            'title'              => ['rendered' => get_the_title($p)],
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, not ours.
            'content'            => ['rendered' => apply_filters('the_content', $p->post_content)],
            'excerpt'            => ['rendered' => get_the_excerpt($p)],
            'author_name'        => get_the_author_meta('display_name', (int) $p->post_author),
            'featured_media_url' => $thumb_url,
            'permalink'          => (string) get_permalink($p),
        ];
        if ($manifest !== null) {
            $styles = BlockStyles::collect_for_post($p, $manifest);
            if ($styles !== null) {
                $row['content_styles'] = $styles;
            }
        }
        return $row;
    }

    /**
     * Resolve same-category sibling posts (with most-recent fallback) for a
     * matched `wp:posts` entry. Returns an array of compact post shapes
     * suitable for `{{#each related}}…{{/each}}` substitution in a dynamic-
     * route template.
     *
     * Called from InlineRenderer::render_dynamic_route() AFTER the dataset
     * has been resolved — once per request rather than once per dataset row
     * — so the per-row dataset cache stays cheap.
     *
     * @param array<string, mixed> $entry Matched `wp:posts` shape (must have
     *                                    integer `id`).
     * @return array<int, array<string, mixed>>
     */
    public static function related_for_post(array $entry): array {
        if (!isset($entry['id']) || !is_int($entry['id']) || $entry['id'] <= 0) {
            return [];
        }
        $post = get_post($entry['id']);
        if (!($post instanceof \WP_Post) || $post->post_type !== 'post') {
            return [];
        }

        /**
         * Filter the related-posts result count. Default 3, clamped 1–12 to
         * keep the per-request WP_Query bounded.
         *
         * @param int     $count Default 3.
         * @param WP_Post $post  The post the related list is for.
         */
        $count = (int) apply_filters('dsgo_apps_related_posts_count', 3, $post);
        $count = max(1, min(12, $count));

        $related_posts = self::compute_default_related($post, $count);

        /**
         * Filter the resolved related-post WP_Post objects before they are
         * shaped for template substitution. Apps can replace the default
         * same-category-with-recent-fallback algorithm here (cross-taxonomy
         * joins, manually curated lists, etc.).
         *
         * @param WP_Post[] $related_posts Default same-category siblings
         *                                  with most-recent fallback.
         * @param WP_Post   $post           The matched post.
         */
        $related_posts = apply_filters('dsgo_apps_related_posts', $related_posts, $post);

        $shaped = [];
        foreach ($related_posts as $rp) {
            if ($rp instanceof \WP_Post) {
                $shaped[] = self::shape_related($rp);
            }
        }
        return $shaped;
    }

    /**
     * @return WP_Post[]
     */
    private static function compute_default_related(\WP_Post $post, int $count): array {
        $cat_ids = wp_get_post_categories($post->ID, ['fields' => 'ids']);

        $results = [];
        if (is_array($cat_ids) && $cat_ids !== []) {
            $q = new \WP_Query([
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => $count,
                'post__not_in'        => [$post->ID],
                'category__in'        => array_map('intval', $cat_ids),
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
            ]);
            $results = is_array($q->posts) ? $q->posts : [];
        }

        if ($results === []) {
            $q = new \WP_Query([
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => $count,
                'post__not_in'        => [$post->ID],
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
            ]);
            $results = is_array($q->posts) ? $q->posts : [];
        }

        return $results;
    }

    /**
     * Compact post shape for related-post cards. Subset of shape_post(); omits
     * `content.rendered` (expensive `the_content` filter, unneeded for cards)
     * and `content_styles` (only the matched main post mounts block styles).
     *
     * @return array<string, mixed>
     */
    private static function shape_related(\WP_Post $p): array {
        $thumb_id = (int) get_post_thumbnail_id($p);
        $thumb_url = '';
        if ($thumb_id > 0) {
            $src = wp_get_attachment_image_src($thumb_id, 'medium');
            if (is_array($src) && isset($src[0])) {
                $thumb_url = (string) $src[0];
            }
        }
        return [
            'id'                 => (int) $p->ID,
            'slug'               => (string) $p->post_name,
            'date'               => mysql2date('M j, Y', $p->post_date),
            'date_iso'           => mysql2date('c', $p->post_date),
            'title'              => ['rendered' => get_the_title($p)],
            'excerpt'            => ['rendered' => get_the_excerpt($p)],
            'author_name'        => get_the_author_meta('display_name', (int) $p->post_author),
            'featured_media_url' => $thumb_url,
            'permalink'          => (string) get_permalink($p),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolve_wc_products(): array {
        if (!function_exists('wc_get_products')) {
            return [];
        }
        $products = wc_get_products([
            'status'   => 'publish',
            'limit'    => 500,
            'paginate' => false,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ]);
        if (!is_array($products)) {
            return [];
        }
        $rows = [];
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product) continue;
            $rows[] = self::shape_wc_product($product);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape_wc_product(\WC_Product $product): array {
        $image_id = (int) $product->get_image_id();
        $image_url = '';
        if ($image_id > 0) {
            $src = wp_get_attachment_image_src($image_id, 'large');
            if (is_array($src) && isset($src[0])) {
                $image_url = (string) $src[0];
            }
        }
        return [
            'id'                 => (int) $product->get_id(),
            'slug'               => (string) $product->get_slug(),
            'name'               => (string) $product->get_name(),
            'permalink'          => (string) $product->get_permalink(),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter, not ours.
            'short_description'  => (string) apply_filters('woocommerce_short_description', $product->get_short_description()),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, not ours.
            'description'        => (string) apply_filters('the_content', $product->get_description()),
            'price'              => wp_strip_all_tags(wc_price($product->get_price())),
            'price_amount'       => (string) $product->get_price(),
            'regular_price'      => (string) $product->get_regular_price(),
            'sale_price'         => (string) $product->get_sale_price(),
            'on_sale'            => (bool) $product->is_on_sale(),
            'is_in_stock'        => (bool) $product->is_in_stock(),
            'is_purchasable'     => (bool) $product->is_purchasable(),
            'sku'                => (string) $product->get_sku(),
            'featured_media_url' => $image_url,
            'add_to_cart_url'    => (string) $product->add_to_cart_url(),
        ];
    }
}
