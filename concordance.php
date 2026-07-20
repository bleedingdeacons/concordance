<?php

declare(strict_types=1);

/**
 * Plugin Name: Concordance
 * Description: API client for the AAGBDB Groups API. Standalone plugin with PSR-11 container.
 * Version: 1.6.9
 * Build date: 2026/05/31
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/concordance
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/concordance
 * Contact: thebleedingdeacons@gmail.com
 * License: GNU General Public License (GPL) version 2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
$concordance_plugin_dir = plugin_dir_path(__FILE__);
$concordance_plugin_url = plugin_dir_url(__FILE__);

// get_plugin_data may not be available in CLI context
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

if (function_exists('get_plugin_data')) {
    $concordance_plugin_data = get_plugin_data(__FILE__, false, false);
    define('CONCORDANCE_VERSION', $concordance_plugin_data['Version']);
} else {
    define('CONCORDANCE_VERSION', 'Unknown');
}
define('CONCORDANCE_PLUGIN_DIR', $concordance_plugin_dir);
define('CONCORDANCE_PLUGIN_URL', $concordance_plugin_url);

// Load Composer autoloader (provides psr/container and optimized class map)
$concordance_autoloader = CONCORDANCE_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($concordance_autoloader)) {
    require_once $concordance_autoloader;
} else {
    // Fallback: PSR-4 autoloader for development without Composer vendor
    spl_autoload_register(function ($class) {
        try {
            $prefix = 'Concordance\\';
            $base_dir = CONCORDANCE_PLUGIN_DIR . 'src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance Autoloader Error: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance Autoloader Fatal Error: ' . $e->getMessage());
        }
    });
}

/**
 * Get the Concordance PSR-11 dependency container
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Concordance is not initialized
 */
function concordance(): \Psr\Container\ContainerInterface {
    return \Concordance\Plugin::getContainer();
}

// Initialize on plugins_loaded — no external plugin dependency
add_action('plugins_loaded', function() {
    try {
        if (!class_exists('Concordance\Plugin')) {
            throw new \Exception('Concordance\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Concordance\Plugin::init();

        do_action('concordance/loaded', \Concordance\Plugin::getContainer());

    } catch (\Exception $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Concordance Plugin Initialization Error: ' . $e->getMessage());
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Concordance Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Concordance Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses($message, ['strong' => []]) . '</p></div>';
            });
        }

        return;

    } catch (\Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Concordance Plugin Fatal Error: ' . $e->getMessage());
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Concordance Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Concordance Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 10);

// Plugin activation hook
register_activation_hook(__FILE__, function () {
    // Set default options on first activation (API key must be configured manually via Settings → Concordance)
    if (!get_option('concordance_cache_ttl')) {
        update_option('concordance_cache_ttl', 900);
    }
    // Flush rewrite rules so REST routes register immediately
    flush_rewrite_rules();
});

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Clean up transients
    global $wpdb;
    try {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup has no caching API equivalent.
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_concordance_%',
                '_transient_timeout_concordance_%'
            )
        );

        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance Deactivation Error: Failed to clean up transients. DB error: ' . $wpdb->last_error);
        }
    } catch (\Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('Concordance Deactivation Error: ' . $e->getMessage());
    }

    flush_rewrite_rules();
});