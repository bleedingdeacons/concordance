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
use function update_option;
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
     * @param array<string, mixed> $queryArgs Optional API query parameters.
     * @param int|null $cacheTtl Cache lifetime in seconds (null = use stored option).
     * @return array<string, mixed>|WP_Error
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

            // wp_json_encode() can fail, and md5(false) is md5('') — every
            // distinct query would then share one cache key and be served
            // another query's results. Bypass the cache rather than risk that.
            $encodedArgs = wp_json_encode($queryArgs);
            if ($encodedArgs === false) {
                return $this->client->getGroups($queryArgs);
            }

            $cacheKey = $this->cacheKey('groups_' . md5($encodedArgs));
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
     * @return array<string, mixed>|WP_Error
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

            $cacheKey = $this->cacheKey('group_' . md5((string) $groupId));
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
     * Flush every cached response.
     *
     * Increments the generation every cache key carries, which puts the whole
     * cache out of reach in one option write. The orphaned entries are never
     * read again and expire on their own TTL.
     *
     * This used to DELETE the transient rows out of wp_options directly, which
     * worked only for as long as transients lived in the database. With a
     * persistent object cache set_transient() writes to the cache instead, no
     * rows exist to match, and that DELETE cleared nothing while reporting
     * success -- the failure looked exactly like an already-empty cache. The
     * keys here are md5 hashes of query arguments, so enumerating them to
     * delete one by one was never an option either.
     *
     * @return bool Whether the generation was advanced.
     */
    public function flush(): bool
    {
        return update_option(
            ConcordanceConfiguration::OPTION_CACHE_VERSION,
            $this->cacheVersion() + 1
        );
    }

    /**
     * Prefix a key with the plugin prefix and the current generation.
     */
    private function cacheKey(string $name): string
    {
        return ConcordanceConfiguration::CACHE_PREFIX . 'v' . $this->cacheVersion() . '_' . $name;
    }

    /**
     * The current cache generation, counting from 1.
     *
     * Anything below 1 -- an absent option, a hand-edited row, a value that
     * came back as something other than a number -- reads as 1 rather than 0,
     * so the key shape stays the same however the option is mangled.
     */
    private function cacheVersion(): int
    {
        $version = (int) get_option(ConcordanceConfiguration::OPTION_CACHE_VERSION, 1);

        return $version > 0 ? $version : 1;
    }
}
