<?php
/**
 * Registers DSGo's plugin-scoped abilities so the WordPress MCP Adapter
 * publishes them to remote AI clients (claude.ai Connectors, chatgpt.com
 * Apps, Claude Desktop, Cursor).
 *
 * Distinct from AbilitiesPublisher, which registers app-scoped abilities
 * declared in `abilities.publishes` of an installed app's manifest. These
 * are owned by the plugin itself and live for the plugin's lifetime, not
 * an app's. The two coexist in the same global ability registry.
 *
 * Pro extends this by hooking `dsgo_apps_register_plugin_abilities` from
 * its own DSGo_Abilities_Pro class, so this Lite-side file does not need
 * to know about Pro existing.
 *
 * Spec: docs/superpowers/specs/2026-05-12-mcp-server-design.md
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class DSGoAbilities {

    public const CATEGORY = 'dsgo';

    public static function register(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        self::register_category();
        self::register_list_apps();
        self::register_get_app();
        self::register_list_templates();
        self::register_delete_app();

        // Discovery-surface stubs for the Pro write abilities. On Lite-only
        // sites these stubs satisfy the spec's promise that
        // dsgo/generate-app + dsgo/install-app are visible (so agents can
        // recognise the upgrade path) and that calling them returns the
        // documented riff_feature_disabled code rather than a generic
        // "ability not found" framework error. Pro replaces both stubs in
        // its register() (see DSGo_Abilities_Pro::register_generate_app /
        // register_install_app), which calls wp_unregister_ability first.
        self::register_riff_disabled_stub(
            'dsgo/generate-app',
            __('Generate a DSGo app from a natural-language prompt (Pro)', 'designsetgo-apps'),
            __('Requires DSGo Pro. Stub returns riff_feature_disabled until Pro is active.', 'designsetgo-apps')
        );
        self::register_riff_disabled_stub(
            'dsgo/install-app',
            __('Install a generated DSGo app draft (Pro)', 'designsetgo-apps'),
            __('Requires DSGo Pro. Stub returns riff_feature_disabled until Pro is active.', 'designsetgo-apps')
        );

        // Pro plugin attaches its two write abilities to this hook so the
        // Lite class does not need to know about Pro existing.
        do_action('dsgo_apps_register_plugin_abilities');
    }

    private static function register_riff_disabled_stub(string $name, string $label, string $description): void {
        if (wp_has_ability($name)) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_init', static function () use ($name, $label, $description): void {
            wp_register_ability($name, [
                'label'       => $label,
                'description' => $description,
                'category'    => self::CATEGORY,
                'input_schema'  => ['type' => 'object', 'additionalProperties' => true],
                'output_schema' => ['type' => 'object'],
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'execute_callback'    => static function () {
                    return new \WP_Error(
                        'riff_feature_disabled',
                        __('The Riff feature requires DSGo Pro. Upgrade at https://designsetgo.com/pricing', 'designsetgo-apps'),
                        ['status' => 403]
                    );
                },
            ]);
        });
    }

    private static function register_list_apps(): void {
        if (wp_has_ability('dsgo/list-apps')) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_init', static function (): void {
            wp_register_ability('dsgo/list-apps', [
                'label'       => __('List installed DSGo apps', 'designsetgo-apps'),
                'description' => __('Returns summaries of every DSGo app installed on the site (id, slug, title, version, install date, dynamic-routes flag).', 'designsetgo-apps'),
                'category'    => self::CATEGORY,
                'input_schema'  => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'apps' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'app_id'             => ['type' => 'string'],
                                    'slug'               => ['type' => 'string'],
                                    'title'              => ['type' => 'string'],
                                    'version'            => ['type' => 'string'],
                                    'install_date'       => ['type' => 'string', 'format' => 'date-time'],
                                    'has_dynamic_routes' => ['type' => 'boolean'],
                                ],
                                'required' => ['app_id', 'slug', 'title', 'version'],
                            ],
                        ],
                    ],
                    'required' => ['apps'],
                ],
                // Mirrors GET /dsgo/v1/apps in class-rest-api.php (manage_options).
                // The MCP audience is the admin who connected their AI client; tightening
                // here prevents subscribers from enumerating site app inventory through
                // a different surface than the REST API enforces.
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'execute_callback'    => [self::class, 'execute_list_apps'],
            ]);
        });
    }

    /**
     * @param mixed $input Ignored. The ability has no input.
     * @return array{apps: list<array<string,mixed>>}
     */
    public static function execute_list_apps($input = null): array {
        $posts = get_posts([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        $apps = [];
        foreach ($posts as $post) {
            $manifest = self::manifest_for_post($post->ID);
            $apps[] = [
                'app_id'             => (string) ($manifest['id'] ?? $post->post_name),
                'slug'               => (string) $post->post_name,
                'title'              => (string) ($manifest['name'] ?? get_the_title($post)),
                'version'            => (string) ($manifest['version'] ?? ''),
                'install_date'       => self::post_install_date($post),
                'has_dynamic_routes' => self::manifest_has_dynamic_routes($manifest),
            ];
        }
        return ['apps' => $apps];
    }

    private static function register_get_app(): void {
        if (wp_has_ability('dsgo/get-app')) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_init', static function (): void {
            wp_register_ability('dsgo/get-app', [
                'label'       => __('Get an installed DSGo app by id', 'designsetgo-apps'),
                'description' => __('Returns full summary plus a manifest excerpt (permissions, declared abilities, display mode, route count). Bundle bytes are not included.', 'designsetgo-apps'),
                'category'    => self::CATEGORY,
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'app_id' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{2,63}$'],
                    ],
                    'required'             => ['app_id'],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'app_id'           => ['type' => 'string'],
                        'slug'             => ['type' => 'string'],
                        'title'            => ['type' => 'string'],
                        'version'          => ['type' => 'string'],
                        'install_date'     => ['type' => 'string', 'format' => 'date-time'],
                        'manifest_excerpt' => ['type' => 'object'],
                    ],
                    'required' => ['app_id', 'manifest_excerpt'],
                ],
                // Mirrors GET /dsgo/v1/apps/{id} in class-rest-api.php (manage_options).
                // Manifest excerpts include declared abilities + permissions, which
                // are admin-relevant signals; we don't expose them to subscribers
                // via the REST surface and shouldn't via MCP either.
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'execute_callback'    => [self::class, 'execute_get_app'],
            ]);
        });
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>|\WP_Error
     */
    public static function execute_get_app($input = null) {
        // Required-field validation runs in WP_Ability::execute via the input
        // schema (returns ability_invalid_input before this callback fires).
        $app_id = is_array($input) ? (string) ($input['app_id'] ?? '') : '';
        $post   = $app_id === '' ? null : self::resolve_app_post($app_id);
        if ($post === null) {
            return new \WP_Error('app_not_found', __('No installed app with that id.', 'designsetgo-apps'), ['status' => 404]);
        }

        $manifest = self::manifest_for_post($post->ID);
        $routes   = isset($manifest['routes']) && is_array($manifest['routes']) ? $manifest['routes'] : [];

        $excerpt = [
            'permissions'         => $manifest['permissions'] ?? null,
            'abilities_publishes' => isset($manifest['abilities']['publishes']) && is_array($manifest['abilities']['publishes'])
                ? $manifest['abilities']['publishes']
                : [],
            'display'             => $manifest['display'] ?? null,
            'routes_count'        => count($routes),
        ];

        return [
            'app_id'           => (string) ($manifest['id'] ?? $post->post_name),
            'slug'             => (string) $post->post_name,
            'title'            => (string) ($manifest['name'] ?? get_the_title($post)),
            'version'          => (string) ($manifest['version'] ?? ''),
            'install_date'     => self::post_install_date($post),
            'manifest_excerpt' => $excerpt,
        ];
    }

    private static function register_list_templates(): void {
        if (wp_has_ability('dsgo/list-templates')) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_init', static function (): void {
            wp_register_ability('dsgo/list-templates', [
                'label'       => __('List starter templates available for app generation', 'designsetgo-apps'),
                'description' => __('Returns the starter-template catalog used by dsgo/generate-app. Empty when Pro is not active.', 'designsetgo-apps'),
                'category'    => self::CATEGORY,
                'input_schema'  => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'templates' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'slug'        => ['type' => 'string'],
                                    'name'        => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                ],
                                'required' => ['slug', 'name'],
                            ],
                        ],
                        'pro_required' => ['type' => 'boolean'],
                    ],
                    'required' => ['templates', 'pro_required'],
                ],
                'permission_callback' => '__return_true',
                'execute_callback'    => [self::class, 'execute_list_templates'],
            ]);
        });
    }

    /**
     * @param mixed $input Ignored.
     * @return array{templates: list<array{slug:string,name:string,description:string}>, pro_required: bool}
     */
    public static function execute_list_templates($input = null): array {
        $pro_class = '\\DSGo_Apps_Pro\\Harness_Templates';
        if (!class_exists($pro_class) || !method_exists($pro_class, 'list')) {
            return ['templates' => [], 'pro_required' => true];
        }
        $templates = call_user_func([$pro_class, 'list']);
        $rows = [];
        foreach (is_array($templates) ? $templates : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = (string) ($entry['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $rows[] = [
                'slug'        => $slug,
                'name'        => (string) ($entry['name'] ?? $slug),
                'description' => (string) ($entry['description'] ?? ''),
            ];
        }
        return ['templates' => $rows, 'pro_required' => false];
    }

    private static function register_delete_app(): void {
        if (wp_has_ability('dsgo/delete-app')) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_init', static function (): void {
            wp_register_ability('dsgo/delete-app', [
                'label'       => __('Delete an installed DSGo app', 'designsetgo-apps'),
                'description' => __('Permanently uninstalls the app and removes its content. Requires confirm=true; the agent is expected to surface the destructive nature to the user before calling.', 'designsetgo-apps'),
                'category'    => self::CATEGORY,
                'meta'        => ['annotations' => ['destructive' => true]],
                'input_schema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'app_id'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{2,63}$'],
                        'confirm' => ['type' => 'boolean', 'const' => true],
                    ],
                    'required'             => ['app_id', 'confirm'],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ok'     => ['type' => 'boolean'],
                        'app_id' => ['type' => 'string'],
                    ],
                    'required' => ['ok', 'app_id'],
                ],
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'execute_callback'    => [self::class, 'execute_delete_app'],
            ]);
        });
    }

    /**
     * @param mixed $input
     * @return array{ok: bool, app_id: string}|\WP_Error
     */
    public static function execute_delete_app($input = null) {
        // The schema enforces `app_id` (required + pattern) and `confirm:
        // {const: true}`. Anything reaching this callback already cleared
        // those — but keep a defensive runtime check on `confirm` because
        // input_schema `const` enforcement isn't universal across WP_Ability
        // builds and a destructive call should never proceed without it.
        $app_id  = is_array($input) ? (string) ($input['app_id'] ?? '') : '';
        $confirm = is_array($input) && !empty($input['confirm']);
        if (!$confirm) {
            return new \WP_Error('delete_not_confirmed', __('Set confirm=true to delete this app.', 'designsetgo-apps'), ['status' => 400]);
        }
        $post = self::resolve_app_post($app_id);
        if ($post === null) {
            return new \WP_Error('app_not_found', __('No installed app with that id.', 'designsetgo-apps'), ['status' => 404]);
        }

        // Delegate to the canonical REST cleanup so attachment metadata,
        // ability registrations, cron jobs, and user-storage rows are all
        // unwound the same way wp-admin delete does. Rebuilding the cascade
        // here would drift the moment any of those side-effects move.
        $request = new \WP_REST_Request('DELETE', '/dsgo/v1/apps/' . $app_id);
        $request->set_url_params(['id' => $app_id]);
        $response = RestApi::delete_app($request);
        if ($response instanceof \WP_REST_Response) {
            $data = (array) $response->get_data();
            if (!empty($data['code'])) {
                return new \WP_Error(
                    (string) $data['code'],
                    (string) ($data['message'] ?? ''),
                    ['status' => $response->get_status()],
                );
            }
        }

        return ['ok' => true, 'app_id' => $app_id];
    }

    private static function resolve_app_post(string $app_id): ?\WP_Post {
        if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/', $app_id)) {
            return null;
        }
        $found = get_page_by_path($app_id, OBJECT, PostType::SLUG);
        return $found instanceof \WP_Post && $found->post_status === 'publish' ? $found : null;
    }

    /**
     * RFC3339 install timestamp for an app post. Guards against the
     * '0000-00-00 00:00:00' sentinel WP stores when GMT couldn't be
     * computed at insert time; mysql_to_rfc3339() returns false for that
     * input, which would violate the output schema's date-time format.
     */
    private static function post_install_date(\WP_Post $post): string {
        $candidates = [$post->post_date_gmt, $post->post_date];
        foreach ($candidates as $stamp) {
            if (!is_string($stamp) || $stamp === '' || $stamp === '0000-00-00 00:00:00') {
                continue;
            }
            $iso = mysql_to_rfc3339($stamp);
            if (is_string($iso) && $iso !== '') {
                return $iso;
            }
        }
        return '';
    }

    /**
     * Mirrors AdminPage::manifest_has_dynamic_route at class-admin-page.php:624.
     * A route is "dynamic" only when it declares a non-empty `dataset.source`
     * resolvable by core (wp:/wc:) or by a registered third-party resolver,
     * not when an app simply ships multiple static routes. Agents reading
     * `has_dynamic_routes` use this to gauge Pro feature dependence; a wrong
     * answer leads to wrong upgrade advice.
     */
    private static function manifest_has_dynamic_routes(array $manifest): bool {
        $routes = isset($manifest['routes']) && is_array($manifest['routes']) ? $manifest['routes'] : [];
        foreach ($routes as $route) {
            $source = is_array($route) ? ($route['dataset']['source'] ?? null) : null;
            if (!is_string($source) || $source === '') {
                continue;
            }
            if (str_starts_with($source, 'wp:') || str_starts_with($source, 'wc:')) {
                return true;
            }
            $resolver = apply_filters('dsgo_apps_dataset_resolver', null, $source);
            if (is_callable($resolver)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private static function manifest_for_post(int $post_id): array {
        $raw = get_post_meta($post_id, 'dsgo_apps_manifest', true);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function register_category(): void {
        if (!function_exists('wp_register_ability_category') || !function_exists('wp_has_ability_category')) {
            return;
        }
        if (wp_has_ability_category(self::CATEGORY)) {
            return;
        }
        Abilities_Context::run('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::CATEGORY, [
                'label'       => __('DSGo Apps', 'designsetgo-apps'),
                'description' => __('Manage DSGo apps: list, inspect, generate, install, delete.', 'designsetgo-apps'),
            ]);
        });
    }
}
