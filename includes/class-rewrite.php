<?php
/**
 * Rewrite rule and query vars for prefixed-mount apps.
 *
 * The URL prefix is dynamic — driven by the `dsgo_apps_url_prefix` site option
 * (default `apps`). Root-mount apps don't need a rewrite rule; the
 * InlineRenderer dispatcher catches them at template_redirect.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class Rewrite {

    public const QUERY_VAR       = 'dsgo_app_id';
    public const ROUTE_PATH_VAR  = 'dsgo_route_path';

    public static function register(): void {
        $prefix   = Settings::get_url_prefix();
        $regex    = $prefix === ''
            ? '^([^/]+)(?:/(.+))?/?$'
            : '^' . preg_quote($prefix, '#') . '/([^/]+)(?:/(.+))?/?$';
        // Empty-prefix mode mounts every app slug as a top-level path. Add the
        // rule at 'bottom' so WP's own page/post resolution wins on collision —
        // a real page named "about" still serves before an app slugged "about".
        // With a non-empty prefix the rule can't collide and goes 'top'.
        $position = $prefix === '' ? 'bottom' : 'top';
        add_rewrite_rule(
            $regex,
            'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::ROUTE_PATH_VAR . '=$matches[2]',
            $position,
        );
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^/]+)');
        add_rewrite_tag('%' . self::ROUTE_PATH_VAR . '%', '(.*)');
    }
}
