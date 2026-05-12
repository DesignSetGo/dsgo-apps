<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\ProFeatureGate;
use WP_UnitTestCase;

/**
 * ProFeatureGate is the single knob every Pro-gated feature consults.
 * Returns false by default; Pro registers a filter callback returning
 * true when a license is active. Tests cover the default (Lite-only)
 * behavior and verify the filter contract accepts the feature name.
 */
final class ProFeatureGateTest extends WP_UnitTestCase {

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        parent::tear_down();
    }

    public function test_features_are_disabled_when_no_filter_is_set(): void {
        $this->assertFalse(ProFeatureGate::is_enabled('cron'));
        $this->assertFalse(ProFeatureGate::is_enabled('abilities_publish'));
        $this->assertFalse(ProFeatureGate::is_enabled('cli_deploy'));
    }

    public function test_filter_can_enable_a_specific_feature(): void {
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $enabled, string $feature): bool {
            return $feature === 'cron';
        }, 10, 2);
        $this->assertTrue(ProFeatureGate::is_enabled('cron'));
        $this->assertFalse(ProFeatureGate::is_enabled('webhooks'));
    }

    public function test_filter_can_enable_all_features(): void {
        add_filter('dsgo_apps_pro_feature_enabled', '__return_true');
        $this->assertTrue(ProFeatureGate::is_enabled('cron'));
        $this->assertTrue(ProFeatureGate::is_enabled('dynamic_routes'));
    }
}
