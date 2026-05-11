<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use WP_UnitTestCase;

/**
 * Asserts that the free plugin works without Pro loaded — coupling
 * rule #1 ("free has zero references to Pro").
 *
 * NOTE: this test runs under the chained test:php suite where BOTH
 * plugins are loaded into the same wp-env. To genuinely test
 * standalone behavior we'd need a separate wp-env without Pro
 * mounted. What this test CAN assert is:
 *   - The hook fires correctly when invoked.
 *   - Invoking it produces no fatals regardless of Pro listeners.
 *   - The free plugin's own boot path doesn't reference Pro symbols
 *     (the CI seam check, npm run lint:seam, enforces this
 *     mechanically — see scripts/check-seam.sh).
 *
 * @group standalone
 */
final class FreePluginStandaloneTest extends WP_UnitTestCase
{
    public function test_admin_actions_hook_fires_without_fatal(): void
    {
        // Invoke the hook with each documented context. Even if Pro is
        // listening (it is, in this combined suite), the call must not
        // fatal and the output must be a string.
        ob_start();
        do_action('dsgo_apps_admin_actions', ['page' => 'apps-list']);
        $apps_list_output = ob_get_clean();
        $this->assertIsString($apps_list_output);

        ob_start();
        do_action('dsgo_apps_admin_actions', ['page' => 'app-row', 'app_id' => 'sample-app']);
        $app_row_output = ob_get_clean();
        $this->assertIsString($app_row_output);

        // Unknown context is benign — listeners ignore it, no fatal.
        ob_start();
        do_action('dsgo_apps_admin_actions', ['page' => 'unknown']);
        $unknown_output = ob_get_clean();
        $this->assertSame('', $unknown_output);
    }

    public function test_free_namespace_resolves_independently_of_pro(): void
    {
        // The free plugin's symbols must exist regardless of Pro's load
        // state. Each of these is referenced by Pro via cross-namespace
        // calls; they're free's stable public surface.
        $this->assertTrue(class_exists('DSGo_Apps\\Plugin'),    'Plugin must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\Bundle'),    'Bundle must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\Manifest'),  'Manifest must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\Settings'),  'Settings must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\PostType'),  'PostType must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\Installer'), 'Installer must exist');
        $this->assertTrue(class_exists('DSGo_Apps\\RestApi'),   'RestApi must exist');
    }

    public function test_dsgo_apps_constants_defined(): void
    {
        // Free's constants are the bridge Pro uses to find shared assets
        // (DSGO_APPS_FILE for plugins_url(), DSGO_APPS_PATH for filesystem).
        $this->assertTrue(defined('DSGO_APPS_VERSION'), 'DSGO_APPS_VERSION must be defined by free');
        $this->assertTrue(defined('DSGO_APPS_FILE'),    'DSGO_APPS_FILE must be defined by free');
        $this->assertTrue(defined('DSGO_APPS_PATH'),    'DSGO_APPS_PATH must be defined by free');
    }
}
