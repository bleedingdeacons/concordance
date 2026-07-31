<?php

/**
 * WP-CLI test stubs for Concordance, plus the WordPress salts.
 *
 * The WordPress surface itself comes from bleedingdeacons/wp-mocks — options,
 * transients, the HTTP API, WP_Error, the REST request/response objects, and
 * the rest of what this file used to carry by hand. What is left here is what
 * that package deliberately does not cover:
 *
 *  - **WP-CLI.** Not WordPress. Concordance is the only plugin in the suite
 *    with a CLI command, so there is nothing to share.
 *  - **The auth salts.** Constants describing a particular installation are
 *    left to the consuming plugin by design; Encryption's no-key path derives
 *    from these two.
 *
 * Loaded after the wp-mocks bootstrap, per its ordering rule: anything that
 * defines its own functions must come after Patchwork.
 */

namespace {
    // ── WordPress salts (Encryption's no-key path derives from these) ─────────
    if (!defined('AUTH_KEY')) {
        define('AUTH_KEY', 'test-auth-key-abcdefghijklmnop');
    }
    if (!defined('SECURE_AUTH_KEY')) {
        define('SECURE_AUTH_KEY', 'test-secure-auth-key-qrstuvwxyz');
    }

    /** Marker thrown by the WP_CLI::error() stub (mirrors WP-CLI's ExitException). */
    if (!class_exists('ConcordanceCliExit')) {
        class ConcordanceCliExit extends \RuntimeException
        {
        }
    }

    if (!class_exists('WP_CLI_Command')) {
        class WP_CLI_Command
        {
        }
    }

    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static function error(string $message): void
            {
                $GLOBALS['conc_cli_log'][] = ['error', $message];
                throw new \ConcordanceCliExit($message);
            }

            public static function warning(string $message): void
            {
                $GLOBALS['conc_cli_log'][] = ['warning', $message];
            }

            public static function log(string $message): void
            {
                $GLOBALS['conc_cli_log'][] = ['log', $message];
            }

            public static function success(string $message): void
            {
                $GLOBALS['conc_cli_log'][] = ['success', $message];
            }

            public static function add_command(string $name, mixed $handler): void
            {
                $GLOBALS['conc_cli_commands'][] = $name;
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists('WP_CLI\\Utils\\format_items')) {
        function format_items(string $format, array $items, array $fields): void
        {
            $GLOBALS['conc_cli_formatted'] = ['format' => $format, 'items' => $items, 'fields' => $fields];
        }
    }
}
