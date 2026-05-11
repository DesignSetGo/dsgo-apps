<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests\Fixtures;

/**
 * Fixture: a real callable that satisfies an `execute_php` declaration.
 * Returns a sentinel value so the test can prove the registered callback
 * actually invoked this method (i.e. the publisher routed past the stub).
 */
final class AbilityExecutor {
    public function run(mixed $input = null): array {
        return ['executed' => true, 'input' => $input];
    }
}

/**
 * Fixture: a class without the method named in the manifest. Used to
 * prove that "class loadable but method missing" is a hard install
 * failure (manifest declares a method that doesn't exist).
 */
final class AbilityWithoutMethod {
    public function some_other_method(): void {}
}

namespace DSGo_Apps\Tests;

use DSGo_Apps\AbilitiesPublisher;
use DSGo_Apps\Manifest;
use DSGo_Apps\ManifestError;
use WP_UnitTestCase;

/**
 * Tests for Task 7 of the cron + webhooks plan: execute_php
 * companion-plugin resolution at ability-registration time.
 *
 * Three branches:
 *
 *   1. class_exists($class) && is_callable([$class, $method])
 *      → register the real callback. dsgo.abilities.invoke from PHP
 *        actually runs the companion plugin's code.
 *
 *   2. class_exists($class) && !is_callable([$class, $method])
 *      → throw ManifestError. The manifest names a method that
 *        doesn't exist on a class that does — author bug, the
 *        installer must reject it loudly rather than silently
 *        registering an unfireable callback.
 *
 *   3. !class_exists($class)
 *      → register a sentinel callback that returns WP_Error with code
 *        `execute_php_class_not_loadable`, AND write the ability name
 *        to the `dsgo_apps_inactive_abilities_<app_id>` option so the
 *        admin can surface a "companion plugin required" notice.
 */
final class AbilitiesPublisherExecutePhpTest extends WP_UnitTestCase {

    public function tear_down(): void {
        foreach (['sample', 'broken', 'missing-class'] as $id) {
            AbilitiesPublisher::unregister_for_app($id);
            delete_option('dsgo_apps_owned_abilities_' . $id);
            delete_option('dsgo_apps_inactive_abilities_' . $id);
        }
        parent::tear_down();
    }

    public function test_class_loadable_and_method_callable_registers_real_callback(): void {
        $manifest = $this->manifest_with_publishes('sample', [[
            'name'        => 'sample/run',
            'label'       => 'Run',
            'description' => 'A real php-backed ability.',
            'category'    => 'content',
            'execute_php' => [
                'class'  => Fixtures\AbilityExecutor::class,
                'method' => 'run',
            ],
        ]]);
        AbilitiesPublisher::register_for_app($manifest);

        $this->assertTrue(wp_has_ability('sample/run'));

        // Invoke via the ability surface and assert we hit the fixture
        // (the stub would have returned a `client_only_ability` WP_Error).
        $ability = wp_get_ability('sample/run');
        $result  = $ability->execute(null);
        $this->assertIsArray($result);
        $this->assertSame(['executed' => true, 'input' => null], $result);

        // Class-not-loadable branch is the only one that writes the
        // inactive option — this path must NOT touch it.
        $this->assertSame([], get_option('dsgo_apps_inactive_abilities_sample', []));
    }

    public function test_class_loadable_but_method_missing_throws_hard_error(): void {
        $manifest = $this->manifest_with_publishes('broken', [[
            'name'        => 'broken/run',
            'label'       => 'Run',
            'description' => 'A php ability whose method is missing.',
            'category'    => 'content',
            'execute_php' => [
                'class'  => Fixtures\AbilityWithoutMethod::class,
                'method' => 'nonexistent_method',
            ],
        ]]);
        $this->expectException(ManifestError::class);
        $this->expectExceptionMessage('execute_php_method_not_found');
        AbilitiesPublisher::register_for_app($manifest);
    }

    public function test_class_not_loadable_registers_inactive_and_tracks_option(): void {
        $manifest = $this->manifest_with_publishes('missing-class', [[
            'name'        => 'missing-class/run',
            'label'       => 'Run',
            'description' => 'A php ability whose class is missing entirely.',
            'category'    => 'content',
            'execute_php' => [
                'class'  => 'Acme\\Plugin\\Nonexistent',
                'method' => 'run',
            ],
        ]]);

        // Must NOT throw — companion-plugin absent is a soft state.
        AbilitiesPublisher::register_for_app($manifest);

        $this->assertTrue(wp_has_ability('missing-class/run'));

        $inactive = get_option('dsgo_apps_inactive_abilities_missing-class', []);
        $this->assertIsArray($inactive);
        $this->assertContains('missing-class/run', $inactive);

        // Invocation through the ability surface returns the sentinel error.
        $ability = wp_get_ability('missing-class/run');
        $result  = $ability->execute(null);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('execute_php_class_not_loadable', $result->get_error_code());
    }

    public function test_ability_without_execute_php_keeps_client_only_stub(): void {
        // Regression: a publish entry with no execute_php declaration
        // must keep the existing client_only_ability behavior so the
        // iframe-side @wordpress/abilities invocation pattern still works.
        // The stub deliberately fires `_doing_it_wrong` when a php
        // caller invokes a client-only ability, so we have to declare
        // that expectation up front or the test framework treats it
        // as an unexpected usage notice.
        $this->setExpectedIncorrectUsage('WP_Ability::execute');

        $manifest = $this->manifest_with_publishes('sample', [[
            'name'        => 'sample/client-only',
            'label'       => 'Client only',
            'description' => 'A pure client-side ability with no php callback.',
            'category'    => 'content',
        ]]);
        AbilitiesPublisher::register_for_app($manifest);

        $ability = wp_get_ability('sample/client-only');
        $result  = $ability->execute(null);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('client_only_ability', $result->get_error_code());

        // No inactive tracking on plain client-only abilities.
        $this->assertSame([], get_option('dsgo_apps_inactive_abilities_sample', []));
    }

    public function test_unregister_clears_inactive_option(): void {
        $manifest = $this->manifest_with_publishes('missing-class', [[
            'name'        => 'missing-class/run',
            'label'       => 'Run',
            'description' => 'A php ability whose class is missing entirely.',
            'category'    => 'content',
            'execute_php' => [
                'class'  => 'Acme\\Plugin\\Nonexistent',
                'method' => 'run',
            ],
        ]]);
        AbilitiesPublisher::register_for_app($manifest);
        $this->assertNotEmpty(get_option('dsgo_apps_inactive_abilities_missing-class', []));

        AbilitiesPublisher::unregister_for_app('missing-class');
        $this->assertFalse(get_option('dsgo_apps_inactive_abilities_missing-class', false));
    }

    public function test_re_register_overwrites_inactive_option(): void {
        // First install: companion plugin missing.
        $manifest_inactive = $this->manifest_with_publishes('missing-class', [[
            'name'        => 'missing-class/run',
            'label'       => 'Run',
            'description' => 'A php ability whose class is missing entirely.',
            'category'    => 'content',
            'execute_php' => ['class' => 'Acme\\Plugin\\Nonexistent', 'method' => 'run'],
        ]]);
        AbilitiesPublisher::register_for_app($manifest_inactive);
        $this->assertNotEmpty(get_option('dsgo_apps_inactive_abilities_missing-class', []));

        // Re-install with a different ability whose class IS loadable.
        // The inactive option must be replaced, not appended to.
        $manifest_active = $this->manifest_with_publishes('missing-class', [[
            'name'        => 'missing-class/run',
            'label'       => 'Run',
            'description' => 'Now the companion plugin is installed.',
            'category'    => 'content',
            'execute_php' => ['class' => Fixtures\AbilityExecutor::class, 'method' => 'run'],
        ]]);
        AbilitiesPublisher::register_for_app($manifest_active);
        $this->assertSame([], get_option('dsgo_apps_inactive_abilities_missing-class', []));
    }

    /**
     * @param array<int, array<string, mixed>> $publishes
     */
    private function manifest_with_publishes(string $id, array $publishes): Manifest {
        return Manifest::validate([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => $id,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => ['publishes' => $publishes],
        ]);
    }
}
