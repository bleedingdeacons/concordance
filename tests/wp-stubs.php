<?php

/**
 * WordPress / WP-CLI test stubs for Concordance.
 *
 * Concordance is standalone (no WordPress in the test run). These are minimal,
 * controllable stubs for the surface its non-admin classes touch, backed by
 * $GLOBALS['conc_*'] state the tests set up and assert against, plus the
 * handful of WordPress/WP-CLI classes the code references. Not faithful
 * implementations — just enough to drive the logic.
 */

namespace {
    // ── WordPress salts (Encryption's no-key path derives from these) ─────────
    if (!defined('AUTH_KEY')) {
        define('AUTH_KEY', 'test-auth-key-abcdefghijklmnop');
    }
    if (!defined('SECURE_AUTH_KEY')) {
        define('SECURE_AUTH_KEY', 'test-secure-auth-key-qrstuvwxyz');
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '', private mixed $data = null)
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(): mixed
            {
                return $this->data;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            public function __construct(private mixed $data = null, private int $status = 200)
            {
            }

            public function get_data(): mixed
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @param array<string,mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return array<string,mixed> */
            public function get_query_params(): array
            {
                return $this->params;
            }

            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
        }
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

    /** Minimal $wpdb for ApiCache::flush(). */
    if (!class_exists('ConcordanceFakeWpdb')) {
        class ConcordanceFakeWpdb
        {
            public string $options = 'wp_options';

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function query(string $query): int
            {
                $GLOBALS['conc_wpdb_queries'][] = $query;
                return $GLOBALS['conc_wpdb_query_result'] ?? 0;
            }
        }
    }

    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new ConcordanceFakeWpdb();
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

namespace {
    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['conc_options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value): bool
        {
            $GLOBALS['conc_options'][$name] = $value;
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return $GLOBALS['conc_transients'][$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $ttl = 0): bool
        {
            $GLOBALS['conc_transients'][$key] = $value;
            return true;
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
        {
            return json_encode($data, $options, $depth);
        }
    }
    if (!function_exists('add_action')) {
        function add_action(string $hook, mixed $cb, int $priority = 10, int $args = 1): bool
        {
            return true;
        }
    }
    if (!function_exists('register_rest_route')) {
        function register_rest_route(string $ns, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['conc_rest_routes'][] = $route;
            $GLOBALS['conc_rest_args'][$route] = $args;
            return true;
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can(string $cap): bool
        {
            return $GLOBALS['conc_user_can'] ?? true;
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $str): string
        {
            return trim(strip_tags((string) $str));
        }
    }
    if (!function_exists('absint')) {
        function absint(mixed $val): int
        {
            return abs((int) $val);
        }
    }
    if (!function_exists('is_admin')) {
        function is_admin(): bool
        {
            return $GLOBALS['conc_is_admin'] ?? false;
        }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg(array $args, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        }
    }
    if (!function_exists('wp_remote_request')) {
        function wp_remote_request(string $url, array $args = []): mixed
        {
            $GLOBALS['conc_last_request'] = ['url' => $url, 'args' => $args];
            return $GLOBALS['conc_http_response'] ?? ['response' => ['code' => 200], 'body' => '[]'];
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(mixed $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return (string) ($response['body'] ?? '');
        }
    }
    if (!function_exists('wp_date')) {
        function wp_date(string $format, ?int $timestamp = null): string
        {
            return date($format, $timestamp ?? time());
        }
    }
}
