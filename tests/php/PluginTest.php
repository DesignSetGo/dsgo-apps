<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Plugin;
use WP_UnitTestCase;

class PluginTest extends WP_UnitTestCase {
    public function test_singleton_returns_same_instance(): void {
        $a = Plugin::get_instance();
        $b = Plugin::get_instance();
        $this->assertSame($a, $b);
    }

    public function test_constants_defined(): void {
        $this->assertTrue(defined('DSGO_APPS_VERSION'));
        $this->assertTrue(defined('DSGO_APPS_PATH'));
        $this->assertTrue(defined('DSGO_APPS_URL'));
    }
}
