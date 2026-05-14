<?php
/**
 * Helper for running code inside a WP Abilities API registration context.
 *
 * `wp_register_ability()` and `wp_register_ability_category()` require the
 * current filter stack to name `wp_abilities_api_init` /
 * `wp_abilities_api_categories_init` respectively — they call
 * `_doing_it_wrong()` otherwise. DSGoAbilities and AbilitiesPublisher both
 * registered abilities OUTSIDE that hook (at plugin boot, on webhook/cron
 * dispatch, etc.), so each method open-coded the same
 * `$wp_current_filter[] = ...; try { ... } finally { array_pop(...); }`
 * dance — roughly eight verbatim copies.
 *
 * This collapses that to one `run()` helper shared by both classes.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Abilities_Context {

    /**
     * Run `$fn` with `$filter` pushed onto the global `$wp_current_filter`
     * stack, then pop it again — even if `$fn` throws. Returns whatever
     * `$fn` returns.
     *
     * Exactly equivalent to the inline push/try/finally/pop blocks it
     * replaces: the same single value is pushed and the same single value
     * is popped.
     *
     * @template T
     * @param string        $filter One of `wp_abilities_api_init` or
     *                              `wp_abilities_api_categories_init`.
     * @param callable():T  $fn
     * @return T
     */
    public static function run(string $filter, callable $fn) {
        global $wp_current_filter;
        $wp_current_filter[] = $filter;
        try {
            return $fn();
        } finally {
            array_pop($wp_current_filter);
        }
    }
}
