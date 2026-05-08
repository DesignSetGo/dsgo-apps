<?php
/**
 * Bridge method → required permission map.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

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
            'router.navigate'   => null,
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
