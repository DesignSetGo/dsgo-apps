<?php
/**
 * REST route registration for manifest-declared webhook endpoints.
 *
 * Reads every installed app's stored manifest (post_meta), iterates
 * `webhooks.endpoints[]`, and calls register_rest_route once per
 * endpoint. The route handler delegates straight to
 * WebhookHandler::handle which runs the 10-step pipeline.
 *
 * **Pro feature gate (sole enforcement point).** The very first thing
 * inside register_all() and register() is ProFeatureGate::is_enabled(
 * 'webhooks'); if closed, NO routes are registered. Skipping the gate
 * here would expose the site to unauthenticated webhook callbacks the
 * moment a Pro license expires — there is no second enforcement layer
 * downstream.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class WebhookRouter {

    /**
     * Read every installed app's webhook endpoints from post_meta and
     * register a REST route per endpoint. Bound to `rest_api_init`
     * in class-plugin.php. Idempotent: WordPress dedupes identical
     * register_rest_route calls.
     *
     * Per-app manifest parse failures are swallowed so a single bad
     * manifest doesn't 500 every REST request on the site.
     */
    public static function register_all(): void {
        // ProFeatureGate is the only enforcement point — dispatching a
        // webhook from an unbound route would expose this site to
        // unauthenticated callbacks.
        if (!ProFeatureGate::is_enabled('webhooks')) {
            return;
        }

        $post_ids = get_posts([
            'post_type'      => PostType::SLUG,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) continue;
            $manifest_arr = get_post_meta($post_id, 'dsgo_apps_manifest', true);
            if (!is_array($manifest_arr)) continue;
            $endpoints = $manifest_arr['webhooks']['endpoints'] ?? null;
            if (!is_array($endpoints) || $endpoints === []) continue;
            self::register_routes_for_app($post->post_name, $endpoints);
        }
    }

    /**
     * Register routes for a single (app, endpoints) pair. Used by the
     * Installer after a successful install/update so newly added
     * endpoints become callable without waiting for the next request.
     *
     * @param array<int, array<string, mixed>> $endpoints
     */
    public static function register(string $app_id, array $endpoints): void {
        // Same enforcement point as register_all(). A future caller
        // could otherwise bypass the gate by going through this method.
        if (!ProFeatureGate::is_enabled('webhooks')) {
            return;
        }
        self::register_routes_for_app($app_id, $endpoints);
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     */
    private static function register_routes_for_app(string $app_id, array $endpoints): void {
        foreach ($endpoints as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id'])) {
                continue;
            }
            $endpoint_id = $entry['id'];
            register_rest_route(
                'dsgo/v1',
                "/webhooks/{$app_id}/{$endpoint_id}",
                [
                    'methods'             => 'POST',
                    'callback'            => static fn (\WP_REST_Request $req): \WP_REST_Response =>
                        WebhookHandler::handle($req, $app_id, $endpoint_id),
                    // Webhooks are unauthenticated by WP standards —
                    // the auth credential lives in the request headers
                    // and is verified by WebhookHandler / WebhookAuth.
                    // Letting the REST permission_callback gate would
                    // 401 every request before the pipeline runs.
                    'permission_callback' => '__return_true',
                ],
            );
        }
    }
}
