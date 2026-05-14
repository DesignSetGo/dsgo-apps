<?php
/**
 * Read-side lookups for installed DSGo app posts + their stored manifests.
 *
 * `WebhookHandler`, `AsyncWebhookHandler`, `WebhookRouter`, and
 * `CronDispatcher` each independently re-implemented the same
 * `get_posts(post_type=dsgo_app...)` + `get_post_meta('dsgo_apps_manifest')`
 * + iterate-over-`webhooks.endpoints[]` pattern. This class centralizes that
 * query so the four call sites share one definition of "find the published
 * app(s)" and "resolve a webhook endpoint config" — keeping query semantics
 * (post_type, post_status=publish, no_found_rows) identical across them.
 *
 * The manifest is returned as the raw stored array (post meta), NOT a
 * Manifest object — callers that need a Manifest still build one themselves.
 * Manifests were validated at install time, so a raw array read is safe.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class App_Repository {

    /** Post meta key the manifest array is stored under. */
    private const MANIFEST_META_KEY = 'dsgo_apps_manifest';

    /**
     * Every published DSGo app that has a readable manifest array, as a list
     * of `['post' => WP_Post, 'manifest' => array]` pairs. Apps whose meta is
     * missing or non-array are skipped (a single bad manifest must not break
     * iteration for the rest).
     *
     * @return array<int, array{post:\WP_Post, manifest:array<string,mixed>}>
     */
    public static function find_published_apps(): array {
        $post_ids = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        $out = [];
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $manifest = get_post_meta($post_id, self::MANIFEST_META_KEY, true);
            if (!is_array($manifest)) {
                continue;
            }
            $out[] = ['post' => $post, 'manifest' => $manifest];
        }
        return $out;
    }

    /**
     * Resolve a single published app post by its slug / app id, WITHOUT
     * requiring a readable manifest. Returns the WP_Post, or null when no
     * published post with that slug exists.
     *
     * Used by callers that only need to confirm the app still exists (e.g.
     * CronDispatcher, which tolerates a post with missing manifest meta and
     * falls back to a default timeout).
     */
    public static function find_published_post_by_app_id(string $app_id): ?\WP_Post {
        $posts = get_posts([
            'post_type'      => PostType::SLUG,
            'name'           => $app_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        if ($posts === []) {
            return null;
        }
        return $posts[0] instanceof \WP_Post ? $posts[0] : null;
    }

    /**
     * Resolve a single published app by its slug / app id. Returns
     * `['post' => WP_Post, 'post_id' => int, 'manifest' => array]`, or null
     * when the app is missing or has no readable manifest array.
     *
     * @return array{post:\WP_Post, post_id:int, manifest:array<string,mixed>}|null
     */
    public static function find_published_by_app_id(string $app_id): ?array {
        $post = self::find_published_post_by_app_id($app_id);
        if ($post === null) {
            return null;
        }
        $manifest = get_post_meta($post->ID, self::MANIFEST_META_KEY, true);
        if (!is_array($manifest)) {
            return null;
        }
        return ['post' => $post, 'post_id' => $post->ID, 'manifest' => $manifest];
    }

    /**
     * Resolve the `webhooks.endpoints[]` entry for (app_id, endpoint_id).
     * Returns the endpoint config array, or null when the app, its manifest,
     * the endpoints block, or the matching endpoint id is missing.
     *
     * @return array<string, mixed>|null
     */
    public static function endpoint_config(string $app_id, string $endpoint_id): ?array {
        $app = self::find_published_by_app_id($app_id);
        if ($app === null) {
            return null;
        }
        $endpoints = $app['manifest']['webhooks']['endpoints'] ?? null;
        if (!is_array($endpoints)) {
            return null;
        }
        foreach ($endpoints as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $endpoint_id) {
                return $entry;
            }
        }
        return null;
    }
}
