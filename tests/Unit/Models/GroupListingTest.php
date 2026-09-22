<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Models;

use Concordance\Models\GroupListing;

/*
 * Tests for the GroupListing value object.
 *
 * GroupListing is a pure value object with no WordPress dependencies
 * except getFormattedLastUpdate() which calls wp_date(). That method
 * is tested separately with a function stub.
 */

/**
 * Helper: build a minimal valid API record array.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sampleListingData(array $overrides = []): array
{
    return array_merge([
        'id'              => 42,
        'groupName'       => 'SERENITY',
        'town'            => 'BRISTOL',
        'intergroupName'  => 'BRISTOL INTERGROUP',
        'intergroupId'    => 5,
        'day'             => 'Monday',
        'startTime'       => '19:30',
        'endTime'         => '20:30',
        'lastUpdate'      => '2024-06-15T00:00:00',
    ], $overrides);
}

// ── fromArray factory ───────────────────────────────────────────
describe('fromArray', function () {
    it('creates a listing with the correct values', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect($listing->getId())->toBe(42)
            ->and($listing->getGroupName())->toBe('SERENITY')
            ->and($listing->getTown())->toBe('BRISTOL')
            ->and($listing->getIntergroupName())->toBe('BRISTOL INTERGROUP')
            ->and($listing->getIntergroupId())->toBe(5)
            ->and($listing->getDay())->toBe('Monday')
            ->and($listing->getStartTime())->toBe('19:30')
            ->and($listing->getEndTime())->toBe('20:30')
            ->and($listing->getLastUpdate())->toBe('2024-06-15T00:00:00');
    });

    it('defaults missing fields', function () {
        $listing = GroupListing::fromArray([]);

        expect($listing->getId())->toBe(0)
            ->and($listing->getGroupName())->toBe('')
            ->and($listing->getTown())->toBe('')
            ->and($listing->getIntergroupName())->toBe('')
            ->and($listing->getIntergroupId())->toBe(0)
            ->and($listing->getDay())->toBe('')
            ->and($listing->getStartTime())->toBe('')
            ->and($listing->getEndTime())->toBe('')
            ->and($listing->getLastUpdate())->toBe('');
    });

    it('preserves the raw data', function () {
        $data = sampleListingData(['extraField' => 'bonus']);
        $listing = GroupListing::fromArray($data);

        expect($listing->getRaw())->toBe($data)
            ->and($listing->getRawValue('extraField'))->toBe('bonus')
            ->and($listing->getRawValue('nonexistent'))->toBeNull()
            ->and($listing->getRawValue('nonexistent', 'fallback'))->toBe('fallback');
    });

    it('casts id and intergroupId to int', function () {
        $listing = GroupListing::fromArray(sampleListingData([
            'id'            => '99',
            'intergroupId'  => '7',
        ]));

        expect($listing->getId())->toBe(99)
            ->and($listing->getIntergroupId())->toBe(7);
    });
});

// ── collectionFromResponse ──────────────────────────────────────
describe('collectionFromResponse', function () {
    it('handles a flat array', function () {
        $response = [
            sampleListingData(['id' => 1]),
            sampleListingData(['id' => 2]),
        ];

        $collection = GroupListing::collectionFromResponse($response);

        expect($collection)->toHaveCount(2)
            ->and($collection[0]->getId())->toBe(1)
            ->and($collection[1]->getId())->toBe(2);
    });

    it('handles a results wrapper', function () {
        $response = [
            'results' => [
                sampleListingData(['id' => 10]),
            ],
        ];

        $collection = GroupListing::collectionFromResponse($response);

        expect($collection)->toHaveCount(1)
            ->and($collection[0]->getId())->toBe(10);
    });

    it('handles a data wrapper', function () {
        $response = [
            'data' => [
                sampleListingData(['id' => 20]),
                sampleListingData(['id' => 21]),
            ],
        ];

        $collection = GroupListing::collectionFromResponse($response);

        expect($collection)->toHaveCount(2);
    });

    it('wraps a single object', function () {
        $response = sampleListingData(['id' => 55]);

        $collection = GroupListing::collectionFromResponse($response);

        expect($collection)->toHaveCount(1)
            ->and($collection[0]->getId())->toBe(55);
    });

    it('handles an empty array', function () {
        $collection = GroupListing::collectionFromResponse([]);

        expect($collection)->toHaveCount(0);
    });
});

// ── Display helpers ─────────────────────────────────────────────
describe('display helpers', function () {
    it('gives a time range of start and end', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect($listing->getTimeRange())->toBe('19:30 – 20:30');
    });

    it('gives a time range of the start only when there is no end', function () {
        $listing = GroupListing::fromArray(sampleListingData(['endTime' => '']));

        expect($listing->getTimeRange())->toBe('19:30');
    });

    it('gives an empty time range when there is no start', function () {
        $listing = GroupListing::fromArray(sampleListingData(['startTime' => '']));

        expect($listing->getTimeRange())->toBe('');
    });

    it('has a town when the town is set', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect($listing->hasTown())->toBeTrue();
    });

    it('has no town when the town is empty', function () {
        $listing = GroupListing::fromArray(sampleListingData(['town' => '']));

        expect($listing->hasTown())->toBeFalse();
    });

    it('is valid when the name is set', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect($listing->isValid())->toBeTrue();
    });

    it('is not valid when the name is empty', function () {
        $listing = GroupListing::fromArray(sampleListingData(['groupName' => '']));

        expect($listing->isValid())->toBeFalse();
    });
});

// ── Serialisation ───────────────────────────────────────────────
describe('serialisation', function () {
    it('returns an API-shaped array from toArray', function () {
        $data = sampleListingData();
        $listing = GroupListing::fromArray($data);

        $array = $listing->toArray();

        expect($array['id'])->toBe(42)
            ->and($array['groupName'])->toBe('SERENITY')
            ->and($array['day'])->toBe('Monday')
            ->and($array['startTime'])->toBe('19:30')
            ->and($array)->not->toHaveKey('raw');
    });

    it('matches toArray when JSON-serialised', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect($listing->jsonSerialize())->toBe($listing->toArray());
    });

    it('casts to the group name as a string', function () {
        $listing = GroupListing::fromArray(sampleListingData());

        expect((string) $listing)->toBe('SERENITY');
    });
});

// ── Sorting ─────────────────────────────────────────────────────
describe('sort', function () {
    it('orders by day from Monday through Sunday', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['day' => 'Friday', 'groupName' => 'F'])),
            GroupListing::fromArray(sampleListingData(['day' => 'Monday', 'groupName' => 'M'])),
            GroupListing::fromArray(sampleListingData(['day' => 'Wednesday', 'groupName' => 'W'])),
        ];

        GroupListing::sort($groups, 'day');

        expect($groups[0]->getGroupName())->toBe('M')
            ->and($groups[1]->getGroupName())->toBe('W')
            ->and($groups[2]->getGroupName())->toBe('F');
    });

    it('orders by time chronologically', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['startTime' => '20:00', 'groupName' => 'Late'])),
            GroupListing::fromArray(sampleListingData(['startTime' => '10:00', 'groupName' => 'Early'])),
            GroupListing::fromArray(sampleListingData(['startTime' => '14:00', 'groupName' => 'Mid'])),
        ];

        GroupListing::sort($groups, 'time');

        expect($groups[0]->getGroupName())->toBe('Early')
            ->and($groups[1]->getGroupName())->toBe('Mid')
            ->and($groups[2]->getGroupName())->toBe('Late');
    });

    it('orders by name alphabetically', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['groupName' => 'Zebra'])),
            GroupListing::fromArray(sampleListingData(['groupName' => 'Alpha'])),
            GroupListing::fromArray(sampleListingData(['groupName' => 'Middle'])),
        ];

        GroupListing::sort($groups, 'name');

        expect($groups[0]->getGroupName())->toBe('Alpha')
            ->and($groups[1]->getGroupName())->toBe('Middle')
            ->and($groups[2]->getGroupName())->toBe('Zebra');
    });

    it('orders by day then time', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['day' => 'Tuesday', 'startTime' => '20:00', 'groupName' => 'Tue-Late'])),
            GroupListing::fromArray(sampleListingData(['day' => 'Monday', 'startTime' => '19:00', 'groupName' => 'Mon-Eve'])),
            GroupListing::fromArray(sampleListingData(['day' => 'Tuesday', 'startTime' => '10:00', 'groupName' => 'Tue-Morn'])),
        ];

        GroupListing::sort($groups, 'day,time');

        expect($groups[0]->getGroupName())->toBe('Mon-Eve')
            ->and($groups[1]->getGroupName())->toBe('Tue-Morn')
            ->and($groups[2]->getGroupName())->toBe('Tue-Late');
    });

    it('handles an unknown day gracefully', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['day' => 'Funday', 'groupName' => 'Unknown'])),
            GroupListing::fromArray(sampleListingData(['day' => 'Monday', 'groupName' => 'Known'])),
        ];

        GroupListing::sort($groups, 'day');

        expect($groups[0]->getGroupName())->toBe('Known')
            ->and($groups[1]->getGroupName())->toBe('Unknown');
    });

    it('keeps every element for an unknown sort field', function () {
        $groups = [
            GroupListing::fromArray(sampleListingData(['groupName' => 'B'])),
            GroupListing::fromArray(sampleListingData(['groupName' => 'A'])),
        ];

        GroupListing::sort($groups, 'nonexistent');

        // Unknown sort field returns 0 — PHP's usort is not guaranteed stable,
        // but it should not crash. Just verify both elements survive.
        $names = array_map(fn($g) => $g->getGroupName(), $groups);
        expect($names)->toContain('A')
            ->toContain('B');
    });
});
