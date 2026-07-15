<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Concordance
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

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
