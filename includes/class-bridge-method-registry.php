<?php
/**
 * Single source of truth for bridge method documentation. Loads
 * `dsgo-apps/data/bridge-methods.json` once at plugin boot and exposes
 * lookups by method name. Drives:
 *   - Help_Bridge / dsgo.help.method() — runtime discovery
 *   - Harness section assembler — full method docs for capability sections
 *   - Documentation generators (BRIDGE-API.md mirror)
 *
 * The JSON file is the canonical schema; this class is a thin reader.
 * When a new bridge method ships its docs entry MUST be appended to
 * bridge-methods.json in the same PR.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class Bridge_Method_Registry {

    /** @var array<string, array{signature:string,description:string,errors:string[],examples:string[]}>|null */
    private static ?array $cached = null;

    /**
     * Return the registry entry for $method_name (e.g. "posts.list"), or
     * null if no entry exists. Validates entry shape on first read.
     *
     * @return array{signature:string,description:string,errors:string[],examples:string[]}|null
     */
    public static function get(string $method_name): ?array {
        $all = self::all();
        return $all[$method_name] ?? null;
    }

    /**
     * @return array<string, array{signature:string,description:string,errors:string[],examples:string[]}>
     */
    public static function all(): array {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $path = self::data_path();
        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf(
                'bridge-methods.json missing at %s — every bridge method must have a registry entry',
                $path,
            ));
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('bridge-methods.json is empty');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('bridge-methods.json is not valid JSON');
        }
        $out = [];
        foreach ($decoded as $method_name => $entry) {
            if (!is_string($method_name) || !is_array($entry)) continue;
            // Normalize the entry shape. Defaults make every entry shape-safe.
            $out[$method_name] = [
                'signature'   => is_string($entry['signature'] ?? null)   ? $entry['signature']   : '',
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'errors'      => is_array($entry['errors'] ?? null)
                    ? array_values(array_filter($entry['errors'], 'is_string'))
                    : [],
                'examples'    => is_array($entry['examples'] ?? null)
                    ? array_values(array_filter($entry['examples'], 'is_string'))
                    : [],
            ];
        }
        return self::$cached = $out;
    }

    /** Reset the in-process cache. Test seam only. */
    public static function reset_cache_for_tests(): void {
        self::$cached = null;
    }

    private static function data_path(): string {
        // Plugin root is one level above the includes/ directory.
        return dirname(__DIR__) . '/data/bridge-methods.json';
    }
}
