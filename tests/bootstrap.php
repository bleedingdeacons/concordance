<?php

declare(strict_types=1);

/**
 * Test bootstrap for Concordance (Pest, on PHPUnit)
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything that defines functions or classes of its own — here, tests/wp-stubs.php
 * with its WP-CLI stand-ins — must be required after the Bootstrap::load() call,
 * not before it.
 *
 * The `rest` group is loaded for the route callbacks in GroupListingManager,
 * which are handed a WP_REST_Request and return a WP_REST_Response.
 *
 * Not loaded here: the `sentinel` stub group. Concordance is standalone and
 * does not use the shared logger.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\Doubles\FakeWpdb;
use BleedingDeacons\WpMocks\WpState;

// Pest's launcher does not define PHPUNIT_COMPOSER_INSTALL, which
// vendor/bin/phpunit does and PHPUnit's separate-process template reads to
// load Composer in the child. Without it the child has no autoloader at all,
// and every #[RunInSeparateProcess] test dies before it starts — which is
// what the WP_CLI test in Unit/PluginWpCliTest.php needs, since a defined
// constant cannot be undone.
if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__) . '/vendor/autoload.php');
}

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress', 'rest']);

// Makes plugins_url()/plugin_dir_url() answer with Concordance's own path.
WpState::$pluginSlug = 'concordance';

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('CONCORDANCE_PLUGIN_DIR')) {
    define('CONCORDANCE_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('CONCORDANCE_PLUGIN_URL')) {
    define('CONCORDANCE_PLUGIN_URL', 'http://example.com/wp-content/plugins/concordance/');
}

if (!defined('CONCORDANCE_VERSION')) {
    define('CONCORDANCE_VERSION', '1.0.0');
}

// WP-CLI stubs and the auth salts — the part of the surface wp-mocks does not
// cover. Must come after Bootstrap::load(); see the note above.
require_once __DIR__ . '/wp-stubs.php';

// ApiCache::flush() deletes the cached transients with one statement against
// $wpdb, so the double is global rather than per-test. ApiCacheTest resets it.
$GLOBALS['wpdb'] = new FakeWpdb();
