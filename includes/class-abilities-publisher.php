<?php
/**
 * Server-side ability publisher for DSGo apps.
 *
 * Registers each entry in the manifest's abilities.publishes via wp_register_ability(),
 * with a stub execute_callback that returns client_only_ability. PHP/REST/CLI/AI-Client
 * callers see a structured error pointing at the JS-side @wordpress/abilities surface.
 *
 * Tracks the set of names DSGo owns per app via the dsgo_apps_owned_abilities_<id>
 * WP option (string[]). unregister_for_app uses this to remove only abilities DSGo
 * registered, never touching names other plugins own.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AbilitiesPublisher {

    private const OWNED_OPTION_PREFIX = 'dsgo_apps_owned_abilities_';

    /**
     * Register every entry in $manifest->abilities_publishes. Diffs against the
     * previously-owned set: drops abilities removed in this manifest, adds new ones.
     */
    public static function register_for_app(Manifest $manifest): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $previously_owned = self::owned_names($manifest->id);
        $new_owned = [];

        // Wrap registration in the wp_abilities_api_init action context that
        // wp_register_ability requires.
        global $wp_current_filter;

        // Ensure all referenced categories exist before registering abilities.
        // wp_register_ability_category requires the wp_abilities_api_categories_init context.
        if (function_exists('wp_register_ability_category') && function_exists('wp_has_ability_category')) {
            $wp_current_filter[] = 'wp_abilities_api_categories_init';
            try {
                foreach ($manifest->abilities_publishes as $entry) {
                    $cat = $entry['category'] ?? null;
                    if ($cat && !wp_has_ability_category($cat)) {
                        wp_register_ability_category($cat, [
                            'label'       => ucfirst($cat),
                            'description' => sprintf('Abilities in the %s category.', $cat),
                        ]);
                    }
                }
            } finally {
                array_pop($wp_current_filter);
            }
        }

        $wp_current_filter[] = 'wp_abilities_api_init';
        try {
            $current_names = array_map(static fn (array $a): string => $a['name'], $manifest->abilities_publishes);
            foreach ($previously_owned as $old_name) {
                if (!in_array($old_name, $current_names, true) && function_exists('wp_unregister_ability')) {
                    wp_unregister_ability($old_name);
                }
            }
            foreach ($manifest->abilities_publishes as $entry) {
                if (function_exists('wp_has_ability') && wp_has_ability($entry['name']) && function_exists('wp_unregister_ability')) {
                    wp_unregister_ability($entry['name']);
                }
                wp_register_ability($entry['name'], self::registration_args($entry));
                $new_owned[] = $entry['name'];
            }
        } finally {
            array_pop($wp_current_filter);
        }

        update_option(self::OWNED_OPTION_PREFIX . $manifest->id, $new_owned, false);
    }

    public static function unregister_for_app(string $app_id): void {
        if (!function_exists('wp_unregister_ability')) {
            delete_option(self::OWNED_OPTION_PREFIX . $app_id);
            return;
        }
        foreach (self::owned_names($app_id) as $name) {
            wp_unregister_ability($name);
        }
        delete_option(self::OWNED_OPTION_PREFIX . $app_id);
    }

    /** @return string[] */
    private static function owned_names(string $app_id): array {
        $value = get_option(self::OWNED_OPTION_PREFIX . $app_id, []);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param array{name:string,label:string,description:string,category:string,input_schema?:array,output_schema?:array,annotations:array<string,bool>,timeout_seconds:int} $entry
     */
    private static function registration_args(array $entry): array {
        $args = [
            'label' => $entry['label'],
            'description' => $entry['description'],
            'category' => $entry['category'],
            'permission_callback' => '__return_true',
            'execute_callback' => static function ($input = null) use ($entry) {
                $message = sprintf(
                    /* translators: %s: ability name */
                    __('Ability "%s" is published by a DesignSetGo app and can only be invoked from a browser context. Use @wordpress/abilities executeAbility() in JS, not server-side wp_ai_client_prompt().', 'designsetgo-apps'),
                    $entry['name'],
                );
                // Signal incorrect usage: server-side PHP execution of a client-only ability is
                // always wrong. Attribute to WP_Ability::execute so test tooling can assert on it.
                _doing_it_wrong('WP_Ability::execute', esc_html($message), '1.0.0');
                return new \WP_Error('client_only_ability', $message, ['status' => 501]);
            },
            'meta' => ['annotations' => $entry['annotations']],
        ];
        if (isset($entry['input_schema'])) {
            $args['input_schema'] = $entry['input_schema'];
        }
        if (isset($entry['output_schema'])) {
            $args['output_schema'] = $entry['output_schema'];
        }
        return $args;
    }
}
