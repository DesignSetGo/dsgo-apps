<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Bridge_Method_Registry;
use DSGo_Apps\Help_Bridge;
use WP_UnitTestCase;

/**
 * Tests for the bridge method registry + Help_Bridge.
 *
 * The registry loads `dsgo-apps/data/bridge-methods.json` once at boot and
 * exposes lookups by method name. Help_Bridge is the always-available
 * `dsgo.help.method(name)` bridge surface that the model uses at runtime
 * to learn full method signatures without the harness having to enumerate
 * every method in the prompt.
 */
class HelpBridgeTest extends WP_UnitTestCase {

    public function test_registry_returns_entry_for_a_known_method(): void {
        $entry = Bridge_Method_Registry::get('posts.list');
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('signature',   $entry);
        $this->assertArrayHasKey('description', $entry);
        $this->assertArrayHasKey('errors',      $entry);
        $this->assertArrayHasKey('examples',    $entry);
        $this->assertIsString($entry['signature']);
        $this->assertStringContainsString('dsgo.posts.list', $entry['signature']);
    }

    public function test_registry_returns_null_for_unknown_method(): void {
        $this->assertNull(Bridge_Method_Registry::get('does.not.exist'));
    }

    public function test_registry_covers_every_permission_mapped_method(): void {
        // The registry MUST have an entry for every method in the
        // Permissions::map() bridge surface. Drift here = harness can't
        // look up a method the runtime supports.
        $permissions_array = \DSGo_Apps\Permissions::to_array();
        $missing = [];
        foreach (array_keys($permissions_array) as $method_name) {
            if (Bridge_Method_Registry::get($method_name) === null) {
                $missing[] = $method_name;
            }
        }
        $this->assertSame([], $missing,
            'Registry missing entries for: ' . implode(', ', $missing));
    }

    public function test_help_bridge_method_returns_registry_entry(): void {
        $result = Help_Bridge::method('user.current');
        $this->assertIsArray($result);
        $this->assertStringContainsString('dsgo.user.current', $result['signature']);
    }

    public function test_help_bridge_method_returns_wp_error_for_unknown(): void {
        $result = Help_Bridge::method('frob.nicate');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function test_registry_entry_shape_is_stable(): void {
        // Every entry has the same field set so callers don't have to
        // null-check per-method.
        foreach (Bridge_Method_Registry::all() as $method_name => $entry) {
            $this->assertArrayHasKey('signature',   $entry, "missing signature on $method_name");
            $this->assertArrayHasKey('description', $entry, "missing description on $method_name");
            $this->assertArrayHasKey('errors',      $entry, "missing errors on $method_name");
            $this->assertArrayHasKey('examples',    $entry, "missing examples on $method_name");
            $this->assertIsArray($entry['errors'],   "errors not array on $method_name");
            $this->assertIsArray($entry['examples'], "examples not array on $method_name");
        }
    }
}
