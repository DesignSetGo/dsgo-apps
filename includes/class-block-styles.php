<?php
/**
 * Collect block + theme stylesheets that go alongside a post's rendered HTML
 * when the manifest opts in via `content.blockStyles` / `content.themeStyles`.
 *
 * The collector runs at content-fetch time (REST / dataset resolution) and
 * returns a structured payload of `links` (absolute URLs) + `inline` (CSS
 * source) + `sources` (the resolved-source list, useful for debugging) +
 * `budget` (used / cap byte counts). Apps load the result via the SDK
 * `applyBlockStyles()` helper.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class BlockStyles {

    /** Header the bridge sets on REST calls so we can resolve the app's manifest. */
    public const REQUEST_HEADER = 'X-DSGo-App-Id';

    /**
     * Hard byte cap for the combined inline+linked-stylesheet payload. Keeps
     * a runaway theme from blowing up dataset rows. Authors hit it rarely;
     * when they do, a `_doing_it_wrong`-style admin notice surfaces it via
     * the `dsgo_apps_block_styles_overflow` action so they can switch to
     * `"core"` or tighten the allowlist.
     */
    private const BYTE_CAP = 262144; // 256 KB

    /**
     * Default style handles registered by the partner DesignSetGo Blocks
     * plugin (see https://github.com/jnealey88/designsetgo). The frontend
     * bundle plus the inline-only handles cover all on-page styling; the
     * editor-only `designsetgo-extensions` handle is intentionally omitted.
     * Hosts can override via the `dsgo_apps_partner_style_handles` filter.
     *
     * @var string[]
     */
    private const DSGO_HANDLES = [
        'designsetgo-frontend',
        'designsetgo-button-global-styles',
        'designsetgo-overlay-header-color',
        'designsetgo-sticky-header',
    ];

    /**
     * Collect the styles payload for a post given a manifest. Returns null
     * when the manifest opts out (the common case) so the caller can skip
     * adding the field at all.
     *
     * @return array{
     *     links:string[],
     *     inline:string,
     *     sources:string[],
     *     budget:array{used:int, cap:int}
     * }|null
     */
    public static function collect_for_post(\WP_Post $post, Manifest $manifest): ?array {
        if ($manifest->content_block_styles === [] && $manifest->content_theme_styles === 'off') {
            return null;
        }

        $cache_key = self::cache_key($post, $manifest);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $sources = [];
        $links   = [];
        $inline  = '';

        $sources_set = array_fill_keys($manifest->content_block_styles, true);

        // Core block library — predictable, covers ~all native blocks.
        if (isset($sources_set['core'])) {
            [$l, $i] = self::resolve_handles(['wp-block-library', 'wp-block-library-theme']);
            $links  = array_merge($links, $l);
            $inline .= $i;
            $sources[] = 'core';
        }

        // DesignSetGo Blocks (partner plugin). Filter lets other partner
        // plugins self-register without code changes here.
        if (isset($sources_set['designsetgo'])) {
            $partner_handles = apply_filters('dsgo_apps_partner_style_handles', self::DSGO_HANDLES);
            if (self::dsgo_active() || $partner_handles !== self::DSGO_HANDLES) {
                [$l, $i] = self::resolve_handles(is_array($partner_handles) ? $partner_handles : self::DSGO_HANDLES);
                $links  = array_merge($links, $l);
                $inline .= $i;
                $sources[] = 'designsetgo';
            }
        }

        // Per-post block detection — picks up third-party blocks the post
        // actually contains.
        if (isset($sources_set['auto'])) {
            $block_names = self::collect_block_names($post->post_content);
            $block_names = self::apply_filters_lists(
                $block_names,
                $manifest->content_block_styles_allowlist,
                $manifest->content_block_styles_denylist,
            );
            $auto_handles = self::handles_for_block_names($block_names);
            if ($auto_handles !== []) {
                [$l, $i] = self::resolve_handles($auto_handles);
                $links  = array_merge($links, $l);
                $inline .= $i;
            }
            $sources[] = 'auto';
        }

        // Theme global styles (theme.json compiled CSS) — scoped enough that
        // it composes with app layout without colliding the way a full
        // `style.css` would.
        if ($manifest->content_theme_styles === 'global') {
            $global = function_exists('wp_get_global_stylesheet') ? wp_get_global_stylesheet() : '';
            if (is_string($global) && $global !== '') {
                $inline .= "\n/* dsgo: theme global */\n" . $global;
            }
            $sources[] = 'theme:global';
        }

        $links = array_values(array_unique($links));

        // Filter hook so hosts can mutate the payload (e.g. swap CDN URLs,
        // strip a noisy handle, prepend their own resets).
        $payload = [
            'links'   => $links,
            'inline'  => $inline,
            'sources' => $sources,
            'budget'  => ['used' => 0, 'cap' => self::BYTE_CAP],
        ];
        $payload = apply_filters('dsgo_apps_block_styles', $payload, $post, $manifest);

        // Re-normalize after the filter (host could have returned anything).
        $links  = is_array($payload['links']  ?? null) ? array_values(array_filter($payload['links'],  'is_string')) : [];
        $inline = is_string($payload['inline'] ?? null) ? $payload['inline'] : '';
        $sources = is_array($payload['sources'] ?? null) ? array_values(array_filter($payload['sources'], 'is_string')) : [];

        $used = strlen($inline) + array_sum(array_map('strlen', $links));
        if ($used > self::BYTE_CAP) {
            // Truncate inline first (it's the bulky part); links stay since
            // they're just references, not bytes downloaded by us.
            $overflow = $used - self::BYTE_CAP;
            $inline = substr($inline, 0, max(0, strlen($inline) - $overflow));
            $used = strlen($inline) + array_sum(array_map('strlen', $links));
            do_action('dsgo_apps_block_styles_overflow', $post, $manifest, $used, self::BYTE_CAP);
        }

        $out = [
            'links'   => $links,
            'inline'  => $inline,
            'sources' => $sources,
            'budget'  => ['used' => $used, 'cap' => self::BYTE_CAP],
        ];

        $ttl = (int) apply_filters('dsgo_apps_block_styles_cache_ttl', HOUR_IN_SECONDS, $post, $manifest);
        if ($ttl > 0) {
            set_transient($cache_key, $out, $ttl);
        }
        return $out;
    }

    /**
     * Cache key embeds the per-app cache version (so manifest edits flush
     * everything via a single `bump_cache_version()`), the post ID + modified
     * timestamp, and a hash of the styles signature so different content-block
     * shapes inside the same app don't collide.
     */
    private static function cache_key(\WP_Post $post, Manifest $manifest): string {
        $version = InlineRenderer::cache_version($manifest->id);
        $signature = md5(implode('|', [
            implode(',', $manifest->content_block_styles),
            $manifest->content_theme_styles,
            implode(',', $manifest->content_block_styles_allowlist),
            implode(',', $manifest->content_block_styles_denylist),
        ]));
        $modified = md5((string) $post->post_modified_gmt);
        return "dsgo_bs:{$manifest->id}:{$version}:{$post->ID}:{$modified}:{$signature}";
    }

    /**
     * Probe for the partner plugin. Constants and class names confirmed
     * against the public DesignSetGo Blocks repo; falling back to the plugin
     * file path covers installs that loaded the plugin without bootstrapping
     * its constant yet.
     */
    private static function dsgo_active(): bool {
        if (defined('DESIGNSETGO_VERSION') || defined('DESIGNSETGO_PLUGIN_VERSION')) {
            return true;
        }
        if (class_exists('\\DesignSetGo\\Plugin') || class_exists('\\DesignSetGo\\Assets')) {
            return true;
        }
        if (function_exists('is_plugin_active')) {
            return is_plugin_active('designsetgo/designsetgo.php');
        }
        return false;
    }

    /**
     * Resolve a list of style handles to absolute URLs + inline `after` CSS.
     * Returns [links, inline_css]. Handles that aren't registered (e.g. when
     * a partner plugin isn't active) are silently skipped.
     *
     * @param string[] $handles
     * @return array{0:string[], 1:string}
     */
    private static function resolve_handles(array $handles): array {
        if ($handles === []) {
            return [[], ''];
        }
        if (!function_exists('wp_styles')) {
            return [[], ''];
        }
        $styles = wp_styles();

        // Walk `$dep->deps` so base stylesheets a handle relies on (e.g. a
        // partner handle declared with `array('wp-block-library')`) are
        // emitted before the dependent handle. Mirrors WP's own enqueue
        // ordering; without it `auto`/partner sources can ship leaf CSS
        // that references missing base rules.
        $ordered = [];
        $visited = [];
        $visit = static function (string $handle) use (&$visit, &$ordered, &$visited, $styles): void {
            if (isset($visited[$handle])) {
                return;
            }
            $visited[$handle] = true;
            if (!isset($styles->registered[$handle])) {
                return;
            }
            $dep = $styles->registered[$handle];
            if (is_array($dep->deps)) {
                foreach ($dep->deps as $sub) {
                    if (is_string($sub)) {
                        $visit($sub);
                    }
                }
            }
            $ordered[] = $handle;
        };
        foreach ($handles as $handle) {
            $visit($handle);
        }

        $links  = [];
        $inline = '';
        foreach ($ordered as $handle) {
            $dep = $styles->registered[$handle];
            // Some handles are inline-only (no $src). Skip the URL but still
            // collect their `after` data.
            if (is_string($dep->src) && $dep->src !== '') {
                $url = self::absolute_url((string) $dep->src);
                if ($url !== '') {
                    $links[] = $url;
                }
            }
            $after = $styles->get_data($handle, 'after');
            if (is_array($after)) {
                foreach ($after as $chunk) {
                    if (is_string($chunk) && $chunk !== '') {
                        $inline .= "\n/* dsgo: {$handle} */\n" . $chunk;
                    }
                }
            }
        }
        return [$links, $inline];
    }

    /**
     * Walk parsed blocks recursively and collect every distinct blockName.
     *
     * @return string[]
     */
    private static function collect_block_names(string $post_content): array {
        if ($post_content === '' || !function_exists('parse_blocks')) {
            return [];
        }
        $blocks = parse_blocks($post_content);
        $names = [];
        $stack = $blocks;
        while ($stack !== []) {
            $b = array_pop($stack);
            if (!is_array($b)) continue;
            if (!empty($b['blockName']) && is_string($b['blockName'])) {
                $names[$b['blockName']] = true;
            }
            if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
                foreach ($b['innerBlocks'] as $child) {
                    $stack[] = $child;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * Resolve a list of block names to their registered style handles via
     * `WP_Block_Type_Registry`.
     *
     * @param string[] $block_names
     * @return string[] handles
     */
    private static function handles_for_block_names(array $block_names): array {
        if ($block_names === [] || !class_exists('\\WP_Block_Type_Registry')) {
            return [];
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        $handles = [];
        foreach ($block_names as $name) {
            $type = $registry->get_registered($name);
            if ($type === null) continue;
            // Both `style_handles` (array) and `style` (string|array) appear
            // in the wild depending on registration vintage. Normalize.
            foreach (['style_handles', 'style'] as $prop) {
                if (!isset($type->$prop)) continue;
                $val = $type->$prop;
                if (is_string($val)) {
                    $handles[$val] = true;
                } elseif (is_array($val)) {
                    foreach ($val as $h) {
                        if (is_string($h)) $handles[$h] = true;
                    }
                }
            }
        }
        return array_keys($handles);
    }

    /**
     * @param string[] $names
     * @param string[] $allowlist
     * @param string[] $denylist
     * @return string[]
     */
    private static function apply_filters_lists(array $names, array $allowlist, array $denylist): array {
        if ($allowlist !== []) {
            $names = array_values(array_filter(
                $names,
                static fn (string $n) => self::matches_any($n, $allowlist),
            ));
        }
        if ($denylist !== []) {
            $names = array_values(array_filter(
                $names,
                static fn (string $n) => !self::matches_any($n, $denylist),
            ));
        }
        return $names;
    }

    /**
     * @param string[] $patterns
     */
    private static function matches_any(string $name, array $patterns): bool {
        foreach ($patterns as $p) {
            if ($p === $name) return true;
            if (str_ends_with($p, '/*')) {
                $ns = substr($p, 0, -2);
                if (str_starts_with($name, $ns . '/')) return true;
            } elseif (str_ends_with($p, '*')) {
                $prefix = substr($p, 0, -1);
                if (str_starts_with($name, $prefix)) return true;
            }
        }
        return false;
    }

    /**
     * Wire the REST filter that attaches `content_styles` to bridge-driven
     * responses for `wp/v2/posts` and `wp/v2/pages`. Gated on the
     * `X-DSGo-App-Id` header so non-bridge traffic is unaffected.
     */
    public static function register(): void {
        // Cover the two post types the bridge currently exposes. Custom CPTs
        // exposed via dataset routes go through the dataset path, which calls
        // collect_for_post() directly with a Manifest in hand.
        add_filter('rest_prepare_post', [self::class, 'attach_to_rest_response'], 20, 3);
        add_filter('rest_prepare_page', [self::class, 'attach_to_rest_response'], 20, 3);
    }

    /**
     * REST filter: read the X-DSGo-App-Id header, resolve the manifest, and
     * attach `content_styles` to the response data. No-ops cleanly when the
     * header is missing (non-bridge traffic) or the manifest opts out.
     *
     * @param \WP_REST_Response $response
     * @param \WP_Post          $post
     * @param \WP_REST_Request  $request
     */
    public static function attach_to_rest_response($response, $post, $request) {
        if (!($response instanceof \WP_REST_Response)) {
            return $response;
        }
        if (!($post instanceof \WP_Post)) {
            return $response;
        }
        if (!($request instanceof \WP_REST_Request)) {
            return $response;
        }
        $app_id = (string) $request->get_header(self::REQUEST_HEADER);
        if ($app_id === '') {
            return $response;
        }
        // Validate app_id charset to avoid feeding arbitrary strings into
        // get_page_by_path / cache keys. Same regex the manifest enforces.
        if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/', $app_id)) {
            return $response;
        }
        $manifest = self::load_manifest($app_id);
        if ($manifest === null) {
            return $response;
        }
        $styles = self::collect_for_post($post, $manifest);
        if ($styles === null) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['content_styles'] = $styles;
        $response->set_data($data);
        return $response;
    }

    private static function load_manifest(string $app_id): ?Manifest {
        if (!function_exists('get_page_by_path')) {
            return null;
        }
        $post = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        if (!$post || $post->post_status !== 'publish') {
            return null;
        }
        $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        if (!is_array($raw)) {
            return null;
        }
        try {
            return Manifest::from_array_unchecked($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function absolute_url(string $src): string {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
            return $src;
        }
        // Plugin/theme handles often register with site-relative paths.
        if (str_starts_with($src, '/')) {
            return rtrim((string) site_url(), '/') . $src;
        }
        return $src;
    }
}
