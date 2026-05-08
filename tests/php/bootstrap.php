<?php
/**
 * PHPUnit bootstrap for DesignSetGo Apps.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

$_tests_dir = getenv('WP_TESTS_DIR') ?: rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    echo "Could not find $_tests_dir/includes/functions.php — run scripts/install-wp-tests.sh first.\n";
    exit(1);
}

require_once dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/dsgo-apps.php';
});

require $_tests_dir . '/includes/bootstrap.php';

// Activate hooks don't fire in the test environment, so install the Riff
// sessions table here. dbDelta is idempotent — calling on repeated suite
// runs is harmless.
\DSGo_Apps\Harness_Sessions::install_schema();
