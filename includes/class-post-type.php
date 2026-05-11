<?php
/**
 * dsgo_app custom post type — represents an installed app.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class PostType {

    public const SLUG = 'dsgo_app';

    public static function register(): void {
        register_post_type(self::SLUG, [
            'labels' => [
                'name'          => __('Apps', 'dsgo-apps'),
                'singular_name' => __('App', 'dsgo-apps'),
            ],
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'show_in_admin_bar'  => false,
            'show_in_nav_menus'  => false,
            'has_archive'        => false,
            'rewrite'            => false,
            'query_var'          => false,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'supports'           => ['title'],
            'can_export'         => true,
            'delete_with_user'   => false,
        ]);
    }
}
