<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AbilitiesPublisher;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

class AbilitiesPublisherTest extends WP_UnitTestCase {

    public function tear_down(): void {
        foreach (['sample', 'app-a', 'app-b'] as $id) {
            AbilitiesPublisher::unregister_for_app($id);
            delete_option('dsgo_apps_owned_abilities_' . $id);
        }
        parent::tear_down();
    }

    private function manifest_with_publishes(string $id, array $publishes): Manifest {
        return Manifest::validate([
            'manifest_version' => 1, 'id' => $id, 'name' => $id,
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities' => ['publishes' => $publishes],
        ]);
    }

    public function test_register_for_app_adds_each_ability(): void {
        $manifest = $this->manifest_with_publishes('sample', [
            ['name' => 'sample/alpha', 'label' => 'Alpha', 'description' => 'd', 'category' => 'content'],
            ['name' => 'sample/beta',  'label' => 'Beta',  'description' => 'd', 'category' => 'content'],
        ]);

        AbilitiesPublisher::register_for_app($manifest);

        $this->assertTrue(wp_has_ability('sample/alpha'));
        $this->assertTrue(wp_has_ability('sample/beta'));
    }

    public function test_register_for_app_with_no_publishes_is_noop(): void {
        $manifest = Manifest::validate([
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ]);

        AbilitiesPublisher::register_for_app($manifest);
        $this->assertSame([], get_option('dsgo_apps_owned_abilities_sample', []));
    }

    public function test_register_then_unregister_removes_owned_names(): void {
        $manifest = $this->manifest_with_publishes('sample', [
            ['name' => 'sample/alpha', 'label' => 'Alpha', 'description' => 'd', 'category' => 'content'],
        ]);
        AbilitiesPublisher::register_for_app($manifest);
        $this->assertTrue(wp_has_ability('sample/alpha'));

        AbilitiesPublisher::unregister_for_app('sample');
        $this->assertFalse(wp_has_ability('sample/alpha'));
        $this->assertSame([], get_option('dsgo_apps_owned_abilities_sample', []));
    }

    public function test_unregister_does_not_touch_other_apps_abilities(): void {
        $a = $this->manifest_with_publishes('app-a', [
            ['name' => 'app-a/foo', 'label' => 'Foo', 'description' => 'd', 'category' => 'content'],
        ]);
        $b = $this->manifest_with_publishes('app-b', [
            ['name' => 'app-b/bar', 'label' => 'Bar', 'description' => 'd', 'category' => 'content'],
        ]);
        AbilitiesPublisher::register_for_app($a);
        AbilitiesPublisher::register_for_app($b);

        AbilitiesPublisher::unregister_for_app('app-a');

        $this->assertFalse(wp_has_ability('app-a/foo'));
        $this->assertTrue(wp_has_ability('app-b/bar'));
    }

    public function test_register_replaces_old_owned_set_on_reinstall(): void {
        $first = $this->manifest_with_publishes('sample', [
            ['name' => 'sample/alpha', 'label' => 'A', 'description' => 'd', 'category' => 'content'],
            ['name' => 'sample/beta',  'label' => 'B', 'description' => 'd', 'category' => 'content'],
        ]);
        AbilitiesPublisher::register_for_app($first);

        $second = $this->manifest_with_publishes('sample', [
            ['name' => 'sample/alpha', 'label' => 'A', 'description' => 'd', 'category' => 'content'],
            ['name' => 'sample/gamma', 'label' => 'G', 'description' => 'd', 'category' => 'content'],
        ]);
        AbilitiesPublisher::register_for_app($second);

        $this->assertTrue(wp_has_ability('sample/alpha'));
        $this->assertTrue(wp_has_ability('sample/gamma'));
        $this->assertFalse(wp_has_ability('sample/beta'));
    }

    public function test_stub_callback_returns_client_only_ability_error(): void {
        $manifest = $this->manifest_with_publishes('sample', [
            ['name' => 'sample/alpha', 'label' => 'A', 'description' => 'd', 'category' => 'content'],
        ]);
        AbilitiesPublisher::register_for_app($manifest);

        $this->setExpectedIncorrectUsage('WP_Ability::execute');

        $result = wp_get_ability('sample/alpha')->execute(null);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('client_only_ability', $result->get_error_code());
    }
}
