<?php
/**
 * Bridge method → required permission map.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Permissions {

    /** @var array<string, ?Permission>|null */
    private static ?array $cached_map = null;

    /** @var array<string, ?string>|null */
    private static ?array $cached_array = null;

    /**
     * @return array<string, ?Permission>
     */
    private static function map(): array {
        return self::$cached_map ??= [
            'site.info'         => Permission::SiteInfo,
            'posts.list'        => Permission::Posts,
            'posts.get'         => Permission::Posts,
            'pages.list'        => Permission::Pages,
            'pages.get'         => Permission::Pages,
            'user.current'      => Permission::User,
            'user.can'          => Permission::User,
            'storage.app.get'   => null,
            'storage.app.set'   => null,
            'storage.user.get'  => null,
            'storage.user.set'  => null,
            'bridge.ping'       => null,
            'abilities.list'    => Permission::Abilities,
            'abilities.invoke'  => Permission::Abilities,
            'ai.prompt'         => Permission::Ai,
            'email.send'        => Permission::Email,
            // media.upload is a core, opt-out feature — gated by the WP
            // `upload_files` cap on the REST permission_callback and by the
            // manifest's `media.uploads` flag (default true) at the bridge.
            // No manifest permission is required.
            'media.upload'      => null,
            'router.navigate'   => null,
            'commerce.products.list'           => Permission::Commerce,
            'commerce.products.get'            => Permission::Commerce,
            'commerce.cart.get'                => Permission::Commerce,
            'commerce.cart.add_item'           => Permission::Commerce,
            'commerce.cart.update_item'        => Permission::Commerce,
            'commerce.cart.remove_item'        => Permission::Commerce,
            'commerce.checkout.open_hosted_page' => Permission::Commerce,
        ];
    }

    public static function required(string $method): ?Permission {
        $map = self::map();
        if (!array_key_exists($method, $map)) {
            throw new \InvalidArgumentException(sprintf('Unknown bridge method: %s', $method));
        }
        return $map[$method];
    }

    /**
     * @return array<string, ?string>
     */
    public static function to_array(): array {
        if (self::$cached_array !== null) {
            return self::$cached_array;
        }
        $out = [];
        foreach (self::map() as $method => $perm) {
            $out[$method] = $perm?->value;
        }
        return self::$cached_array = $out;
    }
}
