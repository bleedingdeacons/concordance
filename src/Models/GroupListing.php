<?php

declare(strict_types=1);

namespace Concordance\Models;

if (!defined('ABSPATH')) {
    exit;
}

use JsonSerializable;

/**
 * Class GroupListing
 *
 * Immutable value object representing a group from the AAGBDB API.
 *
 * API response shape:
 * {
 *   "id": 22,
 *   "groupName": "BATH",
 *   "town": "BATH",
 *   "intergroupName": "WILTSHIRE INTERGROUP",
 *   "intergroupId": 62,
 *   "day": "Monday",
 *   "startTime": "19:30",
 *   "endTime": "20:30",
 *   "lastUpdate": "2022-09-01T00:00:00"
 * }
 */
class GroupListing implements JsonSerializable
{
    public function __construct(
        private readonly int $id,
        private readonly string $groupName,
        private readonly string $town,
        private readonly string $intergroupName,
        private readonly int $intergroupId,
        private readonly string $day,
        private readonly string $startTime,
        private readonly string $endTime,
        private readonly string $lastUpdate,
        private readonly array $raw,
    ) {
    }

    /* -----------------------------------------------------------------
       Static Factory
       ----------------------------------------------------------------- */

    /**
     * Create a GroupListing from a raw API response array.
     *
     * @param array $data A single group record from the API.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:              (int) ($data['id'] ?? 0),
            groupName:       (string) ($data['groupName'] ?? ''),
            town:            (string) ($data['town'] ?? ''),
            intergroupName:  (string) ($data['intergroupName'] ?? ''),
            intergroupId:    (int) ($data['intergroupId'] ?? 0),
            day:             (string) ($data['day'] ?? ''),
            startTime:       (string) ($data['startTime'] ?? ''),
            endTime:         (string) ($data['endTime'] ?? ''),
            lastUpdate:      (string) ($data['lastUpdate'] ?? ''),
            raw:             $data,
        );
    }

    /**
     * Create a collection of GroupListings from the raw API response.
     *
     * Handles flat arrays and responses wrapped in a "results" or "data" key.
     *
     * @param array $response The full API response.
     * @return self[]
     */
    public static function collectionFromResponse(array $response): array
    {
        $items = $response;

        if (isset($response['results']) && is_array($response['results'])) {
            $items = $response['results'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $items = $response['data'];
        }

        // If the array is not a list (i.e. it's a single group object), wrap it
        if (!empty($items) && !isset($items[0]) && isset($items['id'])) {
            $items = [$items];
        }

        return array_map(static fn(array $item) => self::fromArray($item), $items);
    }

    /* -----------------------------------------------------------------
       Getters
       ----------------------------------------------------------------- */

    public function getId(): int
    {
        return $this->id;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function getTown(): string
    {
        return $this->town;
    }

    public function getIntergroupName(): string
    {
        return $this->intergroupName;
    }

    public function getIntergroupId(): int
    {
        return $this->intergroupId;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getStartTime(): string
    {
        return $this->startTime;
    }

    public function getEndTime(): string
    {
        return $this->endTime;
    }

    public function getLastUpdate(): string
    {
        return $this->lastUpdate;
    }

    /**
     * Get the original raw API data array.
     *
     * @return array
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Get a value from the original raw data by key.
     *
     * @param string $key     The key to look up.
     * @param mixed  $default Fallback value if the key doesn't exist.
     * @return mixed
     */
    public function getRawValue(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    /* -----------------------------------------------------------------
       Display Helpers
       ----------------------------------------------------------------- */

    /**
     * Get a display-friendly time range (e.g. "19:30 – 20:30").
     *
     * @return string
     */
    public function getTimeRange(): string
    {
        if ($this->startTime === '') {
            return '';
        }

        if ($this->endTime !== '') {
            return $this->startTime . ' – ' . $this->endTime;
        }

        return $this->startTime;
    }

    /**
     * Whether this listing has a town set.
     *
     * @return bool
     */
    public function hasTown(): bool
    {
        return $this->town !== '';
    }

    /**
     * Whether the group has a name set.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->groupName !== '';
    }

    /**
     * Get a formatted last update date (d/m/Y).
     *
     * @return string Empty string if no date or unparseable.
     */
    public function getFormattedLastUpdate(): string
    {
        if ($this->lastUpdate === '') {
            return '';
        }

        $timestamp = strtotime($this->lastUpdate);
        if ($timestamp === false) {
            return $this->lastUpdate;
        }

        return wp_date('d/m/Y', $timestamp);
    }

    /**
     * Convert an upper-case-ish display string to Title Case for the UI.
     *
     * The API returns most string fields in ALL CAPS (e.g.
     * "WILTSHIRE INTERGROUP"). This helper renders them as
     * "Wiltshire Intergroup" for display. Mixed-case input is preserved
     * — we only normalise when the string is dominated by upper case.
     *
     * Small connector words ("of", "and", "the", etc.) are kept lower
     * case unless they are the first word.
     *
     * @param string $value Raw display string from the API.
     * @return string       Title-cased string suitable for display.
     */
    public static function titleCase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Preserve strings that already have mixed case — assume the
        // original casing was intentional (e.g. "McDonald", "AA").
        $letters = preg_replace('/[^A-Za-z]/', '', $value) ?? '';
        if ($letters !== '' && $letters !== strtoupper($letters) && $letters !== strtolower($letters)) {
            return $value;
        }

        $lower = mb_strtolower($value, 'UTF-8');
        $small = ['of', 'and', 'the', 'a', 'an', 'in', 'on', 'at', 'for', 'to', 'by', 'with'];
        $seen  = 0;

        // Capitalise the first letter of each whitespace- or hyphen-separated
        // chunk, skipping small connector words unless they are the first word.
        return preg_replace_callback(
            '/([A-Za-z\x{00C0}-\x{024F}\']+)/u',
            static function (array $m) use ($small, &$seen): string {
                $word      = $m[1];
                $position  = $seen++;
                $lowerWord = mb_strtolower($word, 'UTF-8');

                if ($position > 0 && in_array($lowerWord, $small, true)) {
                    return $lowerWord;
                }

                return mb_strtoupper(mb_substr($lowerWord, 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_substr($lowerWord, 1, null, 'UTF-8');
            },
            $lower
        ) ?? $value;
    }

    /**
     * Get the intergroup name in Title Case for display.
     *
     * @return string
     */
    public function getIntergroupDisplayName(): string
    {
        return self::titleCase($this->intergroupName);
    }

    /* -----------------------------------------------------------------
       Serialisation
       ----------------------------------------------------------------- */

    /**
     * Convert the model to an associative array matching the API shape.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'groupName'       => $this->groupName,
            'town'            => $this->town,
            'intergroupName'  => $this->intergroupName,
            'intergroupId'    => $this->intergroupId,
            'day'             => $this->day,
            'startTime'       => $this->startTime,
            'endTime'         => $this->endTime,
            'lastUpdate'      => $this->lastUpdate,
        ];
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->groupName;
    }

    /* -----------------------------------------------------------------
       Sorting
       ----------------------------------------------------------------- */

    /** @var array<string, int> Day-of-week ordering for sort. */
    private const DAY_ORDER = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    /**
     * Sort a collection of GroupListings by one or more fields.
     *
     * Accepts a comma-separated string of sort fields.
     * Supported fields: day, time, name.
     *
     * Example:
     *   GroupListing::sort($groups, 'day,time');
     *   GroupListing::sort($groups, 'name');
     *
     * @param self[] &$groups  Collection to sort (sorted in place).
     * @param string $sortSpec Comma-separated sort fields.
     * @return void
     */
    public static function sort(array &$groups, string $sortSpec): void
    {
        $fields = array_map('trim', explode(',', $sortSpec));
        $dayOrder = self::DAY_ORDER;

        usort($groups, static function (self $a, self $b) use ($fields, $dayOrder): int {
            foreach ($fields as $field) {
                $cmp = match ($field) {
                    'day'  => ($dayOrder[strtolower($a->day)] ?? 99) <=> ($dayOrder[strtolower($b->day)] ?? 99),
                    'time' => strcmp($a->startTime, $b->startTime),
                    'name' => strcasecmp($a->groupName, $b->groupName),
                    default => 0,
                };

                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });
    }
}
