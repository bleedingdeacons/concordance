<?php

declare(strict_types=1);

namespace Concordance\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Api\ApiClient;
use Concordance\Api\ApiCache;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use Concordance\Models\GroupListing;
use WP_CLI;
use WP_CLI_Command;

use function is_wp_error;

/**
 * Manage the AAGBDB Groups API connection.
 *
 * ## EXAMPLES
 *
 *     wp concordance list
 *     wp concordance test
 *     wp concordance flush-cache
 */
class ConcordanceCli extends WP_CLI_Command
{
    private ApiClient $client;
    private ApiCache $cache;

    public function __construct(ApiClient $client, ApiCache $cache)
    {
        $this->client = $client;
        $this->cache = $cache;
    }

    /**
     * List all groups from the AAGBDB API.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepts table, json, csv, yaml. Default: table.
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to display.
     *
     * [--limit=<limit>]
     * : Maximum number of groups to display. Default: 0 (all).
     *
     * [--intergroup=<id>]
     * : Filter by intergroup ID.
     *
     * [--sort=<fields>]
     * : Sort results by field(s). Comma-separated for multi-sort. Accepts: day, time, name. E.g. --sort=day,time
     *
     * [--no-cache]
     * : Bypass the transient cache and fetch fresh data.
     *
     * ## EXAMPLES
     *
     *     wp concordance list
     *     wp concordance list --format=json
     *     wp concordance list --fields=groupName,day,startTime --limit=10
     *     wp concordance list --intergroup=1
     *     wp concordance list --sort=day
     *     wp concordance list --sort=day,time
     *     wp concordance list --sort=day,time,name --intergroup=1
     *     wp concordance list --no-cache
     *
     * @subcommand list
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function list_groups(array $args, array $assocArgs): void
    {
        $useCache = !isset($assocArgs['no-cache']);

        if ($useCache) {
            $result = $this->cache->getGroups();
        } else {
            $result = $this->client->getGroups();
        }

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $groups = GroupListing::collectionFromResponse($result);

        // Filter by intergroup ID
        if (isset($assocArgs['intergroup'])) {
            $intergroupId = (int) $assocArgs['intergroup'];
            $groups = array_values(array_filter(
                $groups,
                static fn(GroupListing $g) => $g->getIntergroupId() === $intergroupId
            ));
        }

        if (empty($groups)) {
            WP_CLI::warning('No groups returned.');
            return;
        }

        // Sort
        if (isset($assocArgs['sort'])) {
            GroupListing::sort($groups, $assocArgs['sort']);
        }

        // Apply limit
        $limit = (int) ($assocArgs['limit'] ?? 0);
        if ($limit > 0) {
            $groups = array_slice($groups, 0, $limit);
        }

        // Use raw API data for display so all original columns are preserved
        $items = array_map(static fn(GroupListing $g) => $g->getRaw(), $groups);

        // Determine fields from the actual API keys
        $fields = $assocArgs['fields'] ?? implode(',', array_keys($items[0]));

        \WP_CLI\Utils\format_items(
            $assocArgs['format'] ?? 'table',
            $items,
            explode(',', $fields)
        );
    }

    /**
     * Fetch and display a single group by ID.
     *
     * ## OPTIONS
     *
     * <id>
     * : The group identifier.
     *
     * [--format=<format>]
     * : Output format. Accepts table, json, csv, yaml. Default: table.
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to display.
     *
     * ## EXAMPLES
     *
     *     wp concordance get 42
     *     wp concordance get 42 --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function get(array $args, array $assocArgs): void
    {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a group ID.');
        }

        $result = $this->client->getGroup($args[0]);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if (empty($result)) {
            WP_CLI::warning('Group not found.');
            return;
        }

        $group = GroupListing::fromArray($result);
        $item = $group->getRaw();

        $fields = $assocArgs['fields'] ?? implode(',', array_keys($item));
        $format = $assocArgs['format'] ?? 'table';

        \WP_CLI\Utils\format_items(
            $format,
            [$item],
            explode(',', $fields)
        );
    }

    /**
     * Test the API connection.
     *
     * ## EXAMPLES
     *
     *     wp concordance test
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function test(array $args, array $assocArgs): void
    {
        WP_CLI::log('Testing connection to AAGBDB API...');

        $result = $this->client->getGroups();

        if (is_wp_error($result)) {
            WP_CLI::error('Connection failed: ' . $result->get_error_message());
        }

        $groups = GroupListing::collectionFromResponse($result);
        WP_CLI::success(sprintf('API connection successful. Received %d group(s).', count($groups)));
    }

    /**
     * Clear the groups cache.
     *
     * ## EXAMPLES
     *
     *     wp concordance flush-cache
     *
     * @subcommand flush-cache
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function flush_cache(array $args, array $assocArgs): void
    {
        $deleted = $this->cache->flush();
        WP_CLI::success(sprintf('Cleared %d cached entries.', $deleted));
    }

    /**
     * Display the current API configuration.
     *
     * ## EXAMPLES
     *
     *     wp concordance config
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function config(array $args, array $assocArgs): void
    {
        $stored     = get_option(ConcordanceConfiguration::OPTION_API_KEY, '');
        $encryption = new Encryption();
        $apiKey     = $encryption->decrypt($stored);
        $cacheTtl   = get_option(ConcordanceConfiguration::OPTION_CACHE_TTL, ConcordanceConfiguration::DEFAULT_CACHE_TTL);

        $maskedKey = '';
        if (!empty($apiKey)) {
            $maskedKey = substr($apiKey, 0, 8) . str_repeat('*', max(0, strlen($apiKey) - 12)) . substr($apiKey, -4);
        }

        $items = [
            ['Setting' => 'Version', 'Value' => CONCORDANCE_VERSION],
            ['Setting' => 'API Base URL', 'Value' => get_option(ConcordanceConfiguration::OPTION_API_BASE_URL, ConcordanceConfiguration::DEFAULT_API_BASE_URL)],
            ['Setting' => 'API Key', 'Value' => $maskedKey ?: '(not set)'],
            ['Setting' => 'Cache TTL', 'Value' => $cacheTtl . 's'],
            ['Setting' => 'Request Timeout', 'Value' => get_option(ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT, ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT) . 's'],
            ['Setting' => 'REST Namespace', 'Value' => ConcordanceConfiguration::REST_NAMESPACE],
        ];

        \WP_CLI\Utils\format_items('table', $items, ['Setting', 'Value']);
    }

    /**
     * Display the Concordance version.
     *
     * ## EXAMPLES
     *
     *     wp concordance version
     *
     * @param array $args       Positional arguments.
     * @param array $assocArgs  Associative arguments.
     * @return void
     */
    public function version(array $args, array $assocArgs): void
    {
        WP_CLI::log('Concordance ' . CONCORDANCE_VERSION);
    }

}
