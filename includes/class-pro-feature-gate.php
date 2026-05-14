<?php
/**
 * Single gate for Pro-only features.
 *
 * Lite calls ProFeatureGate::is_enabled('<feature>') at every Pro-gated
 * registration site. Returns false by default. Pro registers a callback
 * on `dsgo_apps_pro_feature_enabled` that returns true when a license is
 * active (paying customer, active trial, beta tester).
 *
 * Install never depends on this gate — bundles always install, even when
 * they declare features that will be inert without a license. The runtime
 * is the only thing that branches.
 *
 * Feature names (stable, additive):
 *   - cli_deploy:         CLI install via Application Password
 *   - cron:               manifest scheduled.jobs registration
 *   - webhooks:           manifest webhooks.endpoints registration
 *   - dynamic_routes:     manifest routes[].dataset.source resolution
 *   - abilities_publish:  manifest abilities.publishes + dsgo.abilities.implement
 *   - riff:               in-admin AI app generator + MCP generate/install path
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class ProFeatureGate {

    /**
     * @param string $feature One of: cli_deploy, cron, webhooks, dynamic_routes, abilities_publish, riff
     */
    public static function is_enabled(string $feature): bool {
        return (bool) apply_filters('dsgo_apps_pro_feature_enabled', false, $feature);
    }
}
