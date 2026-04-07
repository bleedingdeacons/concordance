<?php

declare(strict_types=1);

namespace Concordance\Api;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use WP_Error;
use Exception;

use function add_query_arg;
use function get_option;
use function is_wp_error;
use function wp_json_encode;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Class ApiClient
 *
 * Handles all HTTP communication with the AAGBDB Groups API.
 */
class ApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private array $lastResponse = [];

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, ?int $timeout = null)
    {
        $this->baseUrl = $baseUrl ?? $this->getStoredBaseUrl();
        $this->apiKey = $apiKey ?? $this->getStoredApiKey();
        $this->timeout = $timeout ?? $this->getStoredTimeout();
    }

    /**
     * Fetch all groups from the API.
     *
     * @param array $queryArgs Optional query parameters to append to the URL.
     * @return array|WP_Error Decoded JSON array on success, WP_Error on failure.
     */
    public function getGroups(array $queryArgs = []): array|WP_Error
    {
        return $this->request('GET', '/groups/', $queryArgs);
    }

    /**
     * Fetch a single group by its ID.
     *
     * @param int|string $groupId The group identifier.
     * @return array|WP_Error
     */
    public function getGroup(int|string $groupId): array|WP_Error
    {
        return $this->request('GET', '/groups/' . rawurlencode((string) $groupId) . '/');
    }

    /**
     * Generic GET helper — pass any sub-path under /api/.
     *
     * @param string $endpoint e.g. '/groups/' or '/groups/123/'
     * @param array $params Query-string parameters.
     * @return array|WP_Error
     */
    public function get(string $endpoint, array $params = []): array|WP_Error
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Generic POST helper.
     *
     * @param string $endpoint
     * @param array $body
     * @return array|WP_Error
     */
    public function post(string $endpoint, array $body = []): array|WP_Error
    {
        return $this->request('POST', $endpoint, [], $body);
    }

    /**
     * Return the raw WP HTTP response from the last request (useful for debugging).
     *
     * @return array
     */
    public function getLastResponse(): array
    {
        return $this->lastResponse;
    }

    /**
     * Check whether the API connection is working.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        $result = $this->getGroups();
        return !is_wp_error($result);
    }

    /**
     * Perform an HTTP request against the AAGBDB API.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE …).
     * @param string $endpoint Path relative to the base URL.
     * @param array $queryArgs Query-string parameters.
     * @param array $body Body payload for POST/PUT/PATCH.
     * @return array|WP_Error
     */
    private function request(string $method, string $endpoint, array $queryArgs = [], array $body = []): array|WP_Error
    {
        try {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

            if (!empty($queryArgs)) {
                $url = add_query_arg($queryArgs, $url);
            }

            $args = [
                'method'  => strtoupper($method),
                'timeout' => $this->timeout,
                'headers' => $this->buildHeaders(),
            ];

            if (!empty($body) && in_array($args['method'], ['POST', 'PUT', 'PATCH'], true)) {
                $args['body'] = wp_json_encode($body);
            }

            $response = wp_remote_request($url, $args);

            // Store raw response for debugging
            $this->lastResponse = is_wp_error($response) ? [] : (array) $response;

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);

            if ($code < 200 || $code >= 300) {
                return new WP_Error(
                    'concordance_api_error',
                    sprintf('AAGBDB API returned HTTP %d: %s', $code, $raw),
                    ['status' => $code, 'body' => $raw]
                );
            }

            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error(
                    'concordance_json_error',
                    'Failed to parse JSON response from AAGBDB API.',
                    ['raw' => $raw]
                );
            }

            return $data;

        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance API Request Error: ' . $e->getMessage());
            return new WP_Error(
                'concordance_exception',
                $e->getMessage()
            );
        }
    }

    /**
     * Build request headers including authentication.
     *
     * @return array
     */
    private function buildHeaders(): array
    {
        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'X-Api-Key'     => $this->apiKey,
        ];
    }

    /**
     * Retrieve the API key from WordPress options (fallback).
     *
     * @return string
     */
    private function getStoredApiKey(): string
    {
        if (function_exists('get_option')) {
            $stored = (string) get_option(ConcordanceConfiguration::OPTION_API_KEY, '');
            if ($stored === '') {
                return '';
            }
            $encryption = new Encryption();
            return $encryption->decrypt($stored);
        }
        return '';
    }

    /**
     * Retrieve the API base URL from WordPress options (fallback).
     *
     * @return string
     */
    private function getStoredBaseUrl(): string
    {
        if (function_exists('get_option')) {
            return (string) get_option(
                ConcordanceConfiguration::OPTION_API_BASE_URL,
                ConcordanceConfiguration::DEFAULT_API_BASE_URL
            );
        }
        return ConcordanceConfiguration::DEFAULT_API_BASE_URL;
    }

    /**
     * Retrieve the request timeout from WordPress options (fallback).
     *
     * @return int
     */
    private function getStoredTimeout(): int
    {
        if (function_exists('get_option')) {
            return (int) get_option(
                ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT,
                ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT
            );
        }
        return ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT;
    }
}
