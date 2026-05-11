<?php
/**
 * dsgo.help.method() bridge surface — runtime lookup of bridge method docs.
 *
 * This is the model's escape hatch when the harness prompt doesn't include
 * a method's full signature in-context. The harness can enumerate just the
 * methods the manifest activates (Tier 2 sections) and rely on the model
 * to call dsgo.help.method() for anything it needs to discover.
 *
 * Always available — no manifest permission required, no rate limiting.
 * The data is read-only and small enough that abuse isn't a concern.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Help_Bridge {

    /**
     * Look up a bridge method's documentation by name.
     *
     * @return array{signature:string,description:string,errors:string[],examples:string[]}|\WP_Error
     */
    public static function method(string $name): array|\WP_Error {
        $entry = Bridge_Method_Registry::get($name);
        if ($entry === null) {
            return new \WP_Error(
                'not_found',
                sprintf('Unknown bridge method: %s', $name),
                ['status' => 404],
            );
        }
        return $entry;
    }
}
