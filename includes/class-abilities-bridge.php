<?php
/**
 * Bridge layer for the WordPress Abilities API.
 *
 * Apps declare which abilities they may invoke via `abilities.consumes` patterns
 * in the manifest. This class enforces those patterns, applies the
 * `dsgo_apps_can_invoke_ability` filter, and wraps `WP_Ability::execute()` with
 * structured error mapping so the bridge dispatcher can translate failures to
 * BridgeError codes.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AbilitiesBridge {

    public static function pattern_matches(string $pattern, string $name): bool {
        if ($pattern === $name) {
            return true;
        }
        $slash = strpos($pattern, '/');
        if ($slash === false) {
            return false;
        }
        $pat_ns    = substr($pattern, 0, $slash);
        $pat_local = substr($pattern, $slash + 1);
        $name_slash = strpos($name, '/');
        if ($name_slash === false) {
            return false;
        }
        $name_ns    = substr($name, 0, $name_slash);
        $name_local = substr($name, $name_slash + 1);
        if ($pat_ns !== $name_ns) {
            return false;
        }
        if ($pat_local === '*') {
            return $name_local !== '';
        }
        if (str_ends_with($pat_local, '*')) {
            $prefix = substr($pat_local, 0, -1);
            return str_starts_with($name_local, $prefix);
        }
        return false;
    }

    public static function name_in_consumes(string $name, Manifest $manifest): bool {
        foreach ($manifest->abilities_consumes as $pattern) {
            if (self::pattern_matches($pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array{
     *   name:string,label:string,description:string,category:string,
     *   input_schema:?array,output_schema:?array,annotations:array
     * }>
     */
    public static function list_for_app(Manifest $manifest, int $visitor_user_id): array {
        if ($manifest->abilities_consumes === []) {
            return [];
        }
        if (!function_exists('wp_get_abilities')) {
            return [];
        }
        $out = [];
        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            if (!self::name_in_consumes($name, $manifest)) {
                continue;
            }
            $allowed = apply_filters(
                'dsgo_apps_can_invoke_ability',
                true,
                $name,
                [],
                $manifest->id,
                $visitor_user_id,
            );
            if (!$allowed) {
                continue;
            }
            $perm = $ability->check_permissions(null);
            if ($perm !== true) {
                continue;
            }
            $out[] = self::descriptor($ability);
        }
        return $out;
    }

    /**
     * Invoke a consumed ability and return the wire-format result array.
     *
     * Builds a BridgeResult internally and serializes it via to_array() at
     * the boundary — the emitted shape (`ok` + `data` on success; `ok`,
     * `code`, `message` plus optional `reason` / `wp_error_code` on failure)
     * is byte-identical to the inline arrays this method built before.
     *
     * @return array{
     *   ok:bool,
     *   data?:mixed,
     *   code?:string,
     *   reason?:string,
     *   wp_error_code?:string,
     *   message?:string,
     * }
     */
    public static function invoke(string $name, array $args, Manifest $manifest, int $visitor_user_id): array {
        return self::invoke_result($name, $args, $manifest, $visitor_user_id)->to_array();
    }

    /**
     * Internal BridgeResult-returning core of invoke(). Kept separate so the
     * public method stays a thin to_array() boundary.
     */
    private static function invoke_result(string $name, array $args, Manifest $manifest, int $visitor_user_id): BridgeResult {
        if (!self::name_in_consumes($name, $manifest)) {
            return BridgeResult::error(
                'permission_denied',
                sprintf('ability "%s" not allowed by abilities.consumes', $name),
                ['reason' => 'not_in_consumes'],
            );
        }
        if (!function_exists('wp_get_ability')) {
            return BridgeResult::error(
                'not_implemented',
                'wp_get_ability() is not available; WordPress 6.9+ required',
                ['reason' => 'wp_abilities_unavailable'],
            );
        }
        if (!wp_has_ability($name)) {
            return BridgeResult::error(
                'not_found',
                sprintf('ability "%s" is not registered', $name),
            );
        }
        $ability = wp_get_ability($name);
        $allowed = apply_filters(
            'dsgo_apps_can_invoke_ability',
            true,
            $name,
            $args,
            $manifest->id,
            $visitor_user_id,
        );
        if (!$allowed) {
            return BridgeResult::error(
                'permission_denied',
                sprintf('site policy blocks ability "%s" for app "%s"', $name, $manifest->id),
                ['reason' => 'invoker_policy'],
            );
        }
        $result = $ability->execute(empty($args) ? null : $args);
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            if ($code === 'ability_invalid_permissions') {
                return BridgeResult::error(
                    'permission_denied',
                    $result->get_error_message(),
                    ['reason' => 'capability_denied', 'wp_error_code' => $code],
                );
            }
            if (str_starts_with($code, 'rest_invalid_param') || $code === 'ability_input_invalid') {
                return BridgeResult::error(
                    'invalid_params',
                    $result->get_error_message(),
                    ['wp_error_code' => $code],
                );
            }
            return BridgeResult::error(
                'internal_error',
                $result->get_error_message(),
                ['wp_error_code' => $code],
            );
        }
        return BridgeResult::ok($result);
    }

    /**
     * @return array{
     *   name:string,label:string,description:string,category:string,
     *   input_schema:?array,output_schema:?array,annotations:array
     * }
     */
    public static function descriptor(\WP_Ability $ability): array {
        $input       = $ability->get_input_schema();
        $output      = $ability->get_output_schema();
        $meta        = $ability->get_meta();
        $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
        return [
            'name'         => $ability->get_name(),
            'label'        => $ability->get_label(),
            'description'  => $ability->get_description(),
            'category'     => $ability->get_category(),
            'input_schema'  => is_array($input) && $input !== [] ? $input : null,
            'output_schema' => is_array($output) && $output !== [] ? $output : null,
            'annotations'  => $annotations,
        ];
    }
}
