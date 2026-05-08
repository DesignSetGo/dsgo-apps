<?php
/**
 * Sitemap integration: contribute one URL per (app, route) pair.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class SitemapProvider extends \WP_Sitemaps_Provider {

    /**
     * Cross-request cache key for the fully-built URL list.
     * The transient is invalidated by {@see self::invalidate_cache()}, which
     * fires on app install/update/delete and URL prefix changes.
     */
    private const URL_LIST_TRANSIENT = 'dsgo_apps_sitemap_url_list';

    /** Backstop TTL — a botched invalidation can't strand the cache forever. */
    private const URL_LIST_TTL = 12 * HOUR_IN_SECONDS;

    public function __construct() {
        // WP core's sitemap rewrite regex constrains provider names to [a-z]+
        // (no digits or underscores), so this must stay all lowercase letters.
        $this->name = 'dsgoapps';
        $this->object_type = 'dsgo_app';
    }

    /**
     * Drop the cross-request URL list cache. Called from Installer (after a
     * successful install/update) and from the DELETE app handler so the next
     * sitemap request rebuilds fresh.
     */
    public static function invalidate_cache(): void {
        delete_transient(self::URL_LIST_TRANSIENT);
    }

    /**
     * Sitemaps are paginated by **URL count**, not by app count, because
     * each app contributes one URL per route. WP's default per-sitemap cap
     * is 2000 URLs and the spec hard-caps at 50,000; either way we must
     * page on emitted URLs, not on the iterator.
     */
    private const URLS_PER_PAGE = 1000;

    public function get_url_list($page_num, $object_subtype = ''): array {
        $page_num = max(1, (int) $page_num);
        $start = ($page_num - 1) * self::URLS_PER_PAGE;
        $end   = $start + self::URLS_PER_PAGE;

        $all = $this->all_urls();
        return array_slice($all, $start, self::URLS_PER_PAGE);
    }

    public function get_max_num_pages($object_subtype = ''): int {
        $total = count($this->all_urls());
        return max(1, (int) ceil($total / self::URLS_PER_PAGE));
    }

    /**
     * Build the full app→route URL list once per request and memoize it.
     * The provider's two methods (`get_url_list` and `get_max_num_pages`)
     * both need the same data; computing it once keeps the page-count math
     * coherent with the URL emission and avoids two passes over the apps.
     *
     * @return array<int, array{loc:string, lastmod:string}>
     */
    private function all_urls(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $stored = get_transient(self::URL_LIST_TRANSIENT);
        if (is_array($stored)) {
            return $cache = $stored;
        }

        $posts = get_posts([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);

        $urls = [];
        foreach ($posts as $p) {
            $manifest = get_post_meta($p->ID, 'dsgo_apps_manifest', true);
            if (!is_array($manifest)) continue;
            $lastmod = get_post_modified_time('c', true, $p);
            $bundle  = (string) get_post_meta($p->ID, 'dsgo_apps_bundle_path', true);
            foreach ($this->build_urls_for_app($p->post_name, $manifest, $lastmod, $bundle ?: null) as $u) {
                $urls[] = $u;
            }
        }
        set_transient(self::URL_LIST_TRANSIENT, $urls, self::URL_LIST_TTL);
        return $cache = $urls;
    }

    /**
     * @return array<int, array{loc:string, lastmod:string}>
     */
    public function build_urls_for_app(string $slug, array $manifest, string $lastmod, ?string $bundle_dir = null): array {
        if (($manifest['isolation'] ?? 'inline') !== 'inline') {
            return [];
        }
        $mount_mode = $manifest['mount']['mode'] ?? 'prefixed';
        if ($mount_mode === 'root') {
            $base = '';
        } else {
            $prefix = Settings::get_url_prefix();
            $base   = '/' . $prefix . '/' . $slug;
        }
        $bundle_dir ??= rtrim((string) (wp_upload_dir()['basedir'] ?? ''), '/') . '/dsgo-apps/' . $slug;

        $urls = [];
        foreach ($manifest['routes'] ?? [] as $route) {
            $path = $route['path'] ?? '/';
            if (isset($route['dataset']) && is_array($route['dataset']) && str_contains($path, ':')) {
                $param_name = InlineRenderer::extract_param_name($path);
                if ($param_name === null) {
                    continue;
                }
                $entries = InlineRenderer::load_dataset($bundle_dir, $slug, $route);
                $id_field = $route['dataset']['id_field'];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !array_key_exists($id_field, $entry)) {
                        continue;
                    }
                    $value = $entry[$id_field];
                    if (!is_string($value) && !is_int($value) && !is_float($value)) {
                        continue;
                    }
                    $value_str = (string) $value;
                    if ($value_str === '') {
                        continue;
                    }
                    $resolved = str_replace(':' . $param_name, rawurlencode($value_str), $path);
                    $loc_path = $base === '' ? $resolved : $base . $resolved;
                    $urls[] = ['loc' => home_url($loc_path), 'lastmod' => $lastmod];
                }
                continue;
            }
            // Static route — preserve existing behavior.
            if ($base === '') {
                $loc_path = $path === '/' ? '/' : $path;
            } else {
                $loc_path = $base . ($path === '/' ? '/' : $path);
            }
            $urls[] = ['loc' => home_url($loc_path), 'lastmod' => $lastmod];
        }
        return $urls;
    }
}
