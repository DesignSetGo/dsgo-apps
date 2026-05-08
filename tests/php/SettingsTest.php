<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Settings;
use WP_UnitTestCase;

class SettingsTest extends WP_UnitTestCase {

    public function tear_down(): void {
        delete_option(Settings::OPTION_URL_PREFIX);
        delete_option(Settings::OPTION_ROOT_APP_ID);
        parent::tear_down();
    }

    public function test_default_prefix_is_apps(): void {
        $this->assertSame('apps', Settings::get_url_prefix());
    }

    public function test_get_prefix_falls_back_when_option_corrupted(): void {
        update_option(Settings::OPTION_URL_PREFIX, '!!! invalid !!!');
        $this->assertSame('apps', Settings::get_url_prefix());
    }

    public function test_is_valid_url_prefix_accepts_typical_values(): void {
        foreach (['apps', 'mini', 'a', 'a1', 'my-apps', 'apps-2026'] as $good) {
            $this->assertTrue(Settings::is_valid_url_prefix($good), "expected $good to be valid");
        }
    }

    public function test_is_valid_url_prefix_rejects_bad_values(): void {
        foreach (['', '/', 'WP', '1apps', '-apps', 'apps/', str_repeat('x', 32), 'wp-admin', 'wp-json', 'feed'] as $bad) {
            $this->assertFalse(Settings::is_valid_url_prefix($bad), "expected '$bad' to be rejected");
        }
    }

    public function test_sanitize_url_prefix_strips_slashes_and_lowercases(): void {
        $this->assertSame('myprefix', Settings::sanitize_url_prefix('  /MyPrefix/ '));
    }

    public function test_sanitize_url_prefix_keeps_existing_value_on_invalid_input(): void {
        update_option(Settings::OPTION_URL_PREFIX, 'mini');
        $result = Settings::sanitize_url_prefix('!!!bad!!!');
        $this->assertSame('mini', $result);
    }

    public function test_get_root_app_id_returns_null_when_unset(): void {
        $this->assertNull(Settings::get_root_app_id());
    }

    public function test_set_and_clear_root_app_id(): void {
        Settings::set_root_app_id('marketing-site');
        $this->assertSame('marketing-site', Settings::get_root_app_id());
        Settings::set_root_app_id(null);
        $this->assertNull(Settings::get_root_app_id());
    }
}
