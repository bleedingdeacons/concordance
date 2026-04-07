<?php

declare(strict_types=1);

namespace Concordance\Api;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Common\ConcordanceConfiguration;
use WP_Error;
use Exception;

use function get_option;
use function get_transient;
use function is_wp_error;
use function md5;
use function set_transient;
use function wp_json_encode;

/**
 * Class ApiCache
 *
 * WordPress transient caching wrapper for the API client.
 */
class ApiCache
{
    private ApiClient $client;

    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch groups with WordPress transient caching.
     *
     * @param array $queryArgs Optional API query parameters.
     * @param int|null $cacheTtl Cache lifetime in seconds (null = use stored option).
     * @return array|WP_Error
     */
    public function getGroups(array $queryArgs = [], ?int $cacheTtl = null): array|WP_Error
    {
        try {
            $ttl = $cacheTtl ?? (int) get_option(
                ConcordanceConfiguration::OPTION_CACHE_TTL,
                ConcordanceConfiguration::DEFAULT_CACHE_TTL
            );

            // If caching is disabled, pass through directly
            if ($ttl <= 0) {
                return $this->client->getGroups($queryArgs);
            }

            $cacheKey = ConcordanceConfiguration::CACHE_PREFIX . 'groups_' . md5(wp_json_encode($queryArgs));
            $cached = get_transient($cacheKey);

            if (false !== $cached) {
                return $cached;
            }

            $result = $this->client->getGroups($queryArgs);

            if (!is_wp_error($result)) {
                set_transient($cacheKey, $result, $ttl);
            }

            return $result;

        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance Cache Error: ' . $e->getMessage());
            return new WP_Error(
                'concordance_cache_error',
                $e->getMessage()
            );
        }
    }

    /**
     * Fetch a single group with caching.
     *
     * @param int|string $groupId The group identifier.
     * @param int|null $cacheTtl Cache lifetime in seconds.
     * @return array|WP_Error
     */
    public function getGroup(int|string $groupId, ?int $cacheTtl = null): array|WP_Error
    {
        try {
            $ttl = $cacheTtl ?? (int) get_option(
                ConcordanceConfiguration::OPTION_CACHE_TTL,
                ConcordanceConfiguration::DEFAULT_CACHE_TTL
            );

            if ($ttl <= 0) {
                return $this->client->getGroup($groupId);
            }

            $cacheKey = ConcordanceConfiguration::CACHE_PREFIX . 'group_' . md5((string) $groupId);
            $cached = get_transient($cacheKey);

            if (false !== $cached) {
                return $cached;
            }

            $result = $this->client->getGroup($groupId);

            if (!is_wp_error($result)) {
                set_transient($cacheKey, $result, $ttl);
            }

            return $result;

        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Concordance Cache Error: ' . $e->getMessage());
            return new WP_Error(
                'concordance_cache_error',
                $e->getMessage()
            );
        }
    }

    /**
     * Flush all Concordance transient caches.
     *
     * @return int Number of deleted transient rows.
     */
    public function flush(): int
    {
        global $wpdb;

        $prefix = ConcordanceConfiguration::CACHE_PREFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup has no caching API equivalent.
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . $prefix . '%',
                '_transient_timeout_' . $prefix . '%'
            )
        );
    }
}