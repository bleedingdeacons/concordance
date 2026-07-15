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

    /** @var string Option key for selected dashboard display fields */
    public const OPTION_DASHBOARD_FIELDS = 'concordance_dashboard_fields';

    /** @var string Option key for the intergroup ID the dashboard filters on */
    public const OPTION_INTERGROUP_ID = 'concordance_intergroup_id';

    /** @var int Sentinel value meaning "all intergroups, no filter" */
    public const INTERGROUP_ID_ALL = 0;

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

    /**
     * Whitelist of GroupListing fields that may be toggled on the dashboard.
     *
     * The order in this list is the order they will appear in the settings
     * checkbox list and on each dashboard card. Keys map to human-readable
     * labels (used as the field label and the checkbox label).
     *
     * @var array<string, string>
     */
    public const DASHBOARD_FIELDS = [
        'meetingStatus'     => 'Meeting Status',
        'day'               => 'Day',
        'startTime'         => 'Start Time',
        'endTime'           => 'End Time',
        'address1'          => 'Address Line 1',
        'address2'          => 'Address Line 2',
        'address3'          => 'Address Line 3',
        'town'              => 'Town',
        'postcode'          => 'Postcode',
        'latitude'          => 'Latitude',
        'longitude'         => 'Longitude',
        'openWhen'          => 'Open When',
        'wheelchair'        => 'Wheelchair Accessible',
        'hearingAidLoop'    => 'Hearing Aid Loop',
        'signLanguage'      => 'Sign Language',
        'chit'              => 'Chit',
        'closedInformation' => 'Closed Information',
        'notes'             => 'Notes',
        'temporaryNotes'    => 'Temporary Notes',
        'regionHelpline'    => 'Region Helpline',
        'lastUpdated'       => 'Last Updated',
    ];

    /**
     * Sensible defaults shown on a fresh install.
     *
     * @var string[]
     */
    public const DEFAULT_DASHBOARD_FIELDS = [
        'day',
        'startTime',
        'endTime',
        'town',
        'postcode',
        'notes',
    ];

    private function __construct()
    {
    }
}
