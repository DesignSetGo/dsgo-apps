<?php
/**
 * wp-admin head loader: emits a JSON island listing every installed DSGo app
 * with its bundle URL and abilities.publishes descriptors. Read by
 * parent-bridge-publish.ts to register abilities client-side.
 *
 * Also enqueues the publisher script module on every wp-admin page where at
 * least one app has published abilities.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AdminPublisherLoader {

    /** Cache the collection so emit + enqueue don't both pay the cost. */
    private static ?array $collected = null;

    public static function register(): void {
        add_action('admin_head', [self::class, 'emit_config_island'], 1);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_publisher_module']);
    }

    public static function emit_config_island(): void {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        // ProFeatureGate is the only enforcement point for dsgo.abilities.implement.
        // Without this check, free sites would receive the publisher config island
        // and the parent-bridge-publish module would forward ability invocations
        // into app iframes without a Pro license.
        if (!ProFeatureGate::is_enabled('abilities_publish')) {
            return;
        }
        $apps = self::collect_publishing_apps();
        if ($apps === []) {
            return;
        }
        $config = [
            'apps'       => $apps,
            'rest_root'  => esc_url_raw(rest_url()),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ];
        printf(
            '<script type="application/json" id="dsgo-publisher-config">%s</script>' . "\n",
            wp_json_encode($config),
        );
    }

    public static function enqueue_publisher_module(): void {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        if (!ProFeatureGate::is_enabled('abilities_publish')) {
            return;
        }
        if (self::collect_publishing_apps() === []) {
            return;
        }
        $asset_path = DSGO_APPS_PATH . 'assets/parent-bridge-publish.js';
        $version = file_exists($asset_path) ? (string) filemtime($asset_path) : '0';
        wp_register_script_module(
            'designsetgo-apps/publisher',
            plugins_url('assets/parent-bridge-publish.js', DSGO_APPS_FILE),
            [['id' => '@wordpress/abilities', 'import' => 'static']],
            $version,
        );
        wp_enqueue_script_module('designsetgo-apps/publisher');
    }

    /**
     * @return array<int, array{
     *   id:string, bundle_url:string,
     *   permissions:array{read:string[],write:string[]},
     *   abilities:array<int, array{name:string,label:string,description:string,category:string,annotations:array,timeout_seconds:int,input_schema?:array,output_schema?:array}>
     * }>
     */
    private static function collect_publishing_apps(): array {
        if (self::$collected !== null) {
            return self::$collected;
        }
        $posts = get_posts([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);
        $out = [];
        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
            if (!is_array($raw)) continue;
            $publishes = $raw['abilities']['publishes'] ?? [];
            if (!is_array($publishes) || $publishes === []) continue;
            try {
                $manifest = Manifest::validate($raw);
            } catch (ManifestError $e) {
                continue;
            }
            if ($manifest->isolation === 'iframe') {
                $entry_url = Bundle::url_for($manifest->id) . $manifest->entry;
            } else { // inline
                if ($manifest->mount_mode === MountMode::Root) {
                    $entry_url = home_url('/__dsgo-host');
                } else {
                    $entry_url = home_url(Settings::app_base_path($manifest->id) . '/__dsgo-host');
                }
            }
            $out[] = [
                'id'         => $manifest->id,
                'bundle_url' => $entry_url,
                'permissions' => [
                    'read'  => array_map(static fn (Permission $p): string => $p->value, $manifest->permissions_read),
                    'write' => [],
                ],
                'abilities'  => $manifest->abilities_publishes,
            ];
        }
        return self::$collected = $out;
    }
}
