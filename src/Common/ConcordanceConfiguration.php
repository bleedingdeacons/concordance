<?php

declare(strict_types=1);

namespace Concordance\Common;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration Constants for Concordance
 */
final class ConcordanceConfiguration
{
    /** @var string Option key for the API key stored in wp_options */
    public const OPTION_API_KEY = 'concordance_api_key';

    /** @var string Option key for the cache TTL stored in wp_options */
    public const OPTION_CACHE_TTL = 'concordance_cache_ttl';

    /** @var string Option key for the API base URL stored in wp_options */
    public const OPTION_API_BASE_URL = 'concordance_api_base_url';

    /** @var string Option key for the HTTP request timeout stored in wp_options */
    public const OPTION_REQUEST_TIMEOUT = 'concordance_request_timeout';

    /** @var string Default base URL for the AAGBDB API */
    public const DEFAULT_API_BASE_URL = 'https://aagbdb.org.uk/api';

    /** @var int Default cache TTL in seconds (15 minutes) */
    public const DEFAULT_CACHE_TTL = 900;

    /** @var int Default HTTP request timeout in seconds */
    public const DEFAULT_REQUEST_TIMEOUT = 30;

    /** @var string Transient prefix for cached responses */
    public const CACHE_PREFIX = 'concordance_';

    /** @var string REST API namespace */
    public const REST_NAMESPACE = 'concordance/v1';

    private function __construct()
    {
    }
}
