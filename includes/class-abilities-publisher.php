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

    private const OWNED_OPTION_PREFIX    = 'dsgo_apps_owned_abilities_';
    private const INACTIVE_OPTION_PREFIX = 'dsgo_apps_inactive_abilities_';

    /**
     * Register every entry in $manifest->abilities_publishes. Diffs against the
     * previously-owned set: drops abilities removed in this manifest, adds new ones.
     *
     * `execute_php` resolution (per Task 7 of the cron+webhooks plan) happens
     * here at registration time:
     *
     *   - class_exists + method callable → register the real php callback,
     *     so cron / webhooks / dsgo.abilities.invoke from PHP all hit
     *     the companion plugin's implementation.
     *
     *   - class_exists + method missing → throw ManifestError. The author
     *     declared a method that doesn't exist on a class that does;
     *     the install must fail loudly rather than register a callback
     *     that can never be invoked successfully.
     *
     *   - class missing → register a sentinel inactive callback that
     *     returns WP_Error('execute_php_class_not_loadable'). Track the
     *     ability name in `dsgo_apps_inactive_abilities_<app_id>` so the
     *     admin can surface a "companion plugin required" notice and
     *     so the cron dispatcher can downgrade the failure code from
     *     `cron_ability_execute_failed` to `cron_ability_not_found`.
     */
    public static function register_for_app(Manifest $manifest): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $previously_owned = self::owned_names($manifest->id);
        $new_owned    = [];
        $new_inactive = [];

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
                [$args, $is_inactive] = self::registration_args($entry);
                wp_register_ability($entry['name'], $args);
                $new_owned[] = $entry['name'];
                if ($is_inactive) {
                    $new_inactive[] = $entry['name'];
                }
            }
        } finally {
            array_pop($wp_current_filter);
        }

        update_option(self::OWNED_OPTION_PREFIX . $manifest->id, $new_owned, false);
        // Overwrite — never append — so re-installing with a companion
        // plugin now present clears the prior inactive marker. The
        // admin notice consumer reads this option each render.
        update_option(self::INACTIVE_OPTION_PREFIX . $manifest->id, $new_inactive, false);
    }

    public static function unregister_for_app(string $app_id): void {
        if (!function_exists('wp_unregister_ability')) {
            delete_option(self::OWNED_OPTION_PREFIX . $app_id);
            delete_option(self::INACTIVE_OPTION_PREFIX . $app_id);
            return;
        }
        foreach (self::owned_names($app_id) as $name) {
            wp_unregister_ability($name);
        }
        delete_option(self::OWNED_OPTION_PREFIX . $app_id);
        delete_option(self::INACTIVE_OPTION_PREFIX . $app_id);
    }

    /**
     * Read-side accessor for the inactive-abilities tracking option.
     * Consumed by the admin notice that prompts the operator to install
     * the missing companion plugin, and by CronDispatcher when it
     * downgrades the failure code for inactive-class invocations.
     *
     * @return string[]
     */
    public static function inactive_names(string $app_id): array {
        $value = get_option(self::INACTIVE_OPTION_PREFIX . $app_id, []);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return string[] */
    private static function owned_names(string $app_id): array {
        $value = get_option(self::OWNED_OPTION_PREFIX . $app_id, []);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Build the registration args for one published ability. Returns
     * `[args, $is_inactive]`. The caller (register_for_app) tracks
     * inactive abilities in the per-app option.
     *
     * @param array{name:string,label:string,description:string,category:string,input_schema?:array,output_schema?:array,annotations:array<string,bool>,timeout_seconds:int,execute_php?:array{class:string,method:string}} $entry
     * @return array{0:array<string,mixed>,1:bool}
     */
    private static function registration_args(array $entry): array {
        $args = [
            'label'               => $entry['label'],
            'description'         => $entry['description'],
            'category'            => $entry['category'],
            'permission_callback' => '__return_true',
            'execute_callback'    => self::stub_callback($entry['name']),
            'meta'                => ['annotations' => $entry['annotations']],
        ];
        if (isset($entry['input_schema'])) {
            $args['input_schema'] = $entry['input_schema'];
        }
        if (isset($entry['output_schema'])) {
            $args['output_schema'] = $entry['output_schema'];
        }

        $is_inactive = false;
        if (isset($entry['execute_php'])) {
            $class  = $entry['execute_php']['class'];
            $method = $entry['execute_php']['method'];

            if (!class_exists($class, true)) {
                // Companion plugin not installed yet. Register a sentinel
                // callback so the ability surface stays consistent and
                // callers see a clear error code; tracked via the
                // inactive option so the admin can prompt for install.
                $args['execute_callback'] = static fn ($input = null) => new \WP_Error(
                    'execute_php_class_not_loadable',
                    sprintf(
                        /* translators: %s: class name */
                        __('Companion plugin not installed: class %s is not loadable.', 'designsetgo-apps'),
                        $class,
                    ),
                    ['status' => 503],
                );
                $is_inactive = true;
            } elseif (!method_exists($class, $method)) {
                // Class loaded but method doesn't exist — manifest names
                // a method that can't be called. This is an author bug
                // and the install must fail rather than register a
                // permanently-broken ability.
                throw new ManifestError(
                    sprintf('abilities.publishes[%s].execute_php', $entry['name']),
                    sprintf(
                        'execute_php_method_not_found: %s::%s does not exist or is not callable',
                        $class,
                        $method,
                    ),
                );
            } else {
                // Real callback. Instantiates a fresh class instance per
                // invocation so per-call state never leaks across cron
                // ticks or webhook deliveries.
                $args['execute_callback'] = static fn ($input = null) => (new $class())->{$method}($input);
            }
        }

        return [$args, $is_inactive];
    }

    /**
     * The stub execute_callback used when an ability has no `execute_php`
     * declaration. Server-side invocation of a client-only ability is
     * always a programmer mistake — surface it via `_doing_it_wrong` and
     * return a structured WP_Error so test tooling and AI consumers
     * both get a clear signal.
     */
    private static function stub_callback(string $name): callable {
        return static function ($input = null) use ($name) {
            $message = sprintf(
                /* translators: %s: ability name */
                __('Ability "%s" is published by a DesignSetGo app and can only be invoked from a browser context. Use @wordpress/abilities executeAbility() in JS, not server-side wp_ai_client_prompt().', 'designsetgo-apps'),
                $name,
            );
            _doing_it_wrong('WP_Ability::execute', esc_html($message), '1.0.0');
            return new \WP_Error('client_only_ability', $message, ['status' => 501]);
        };
    }
}
