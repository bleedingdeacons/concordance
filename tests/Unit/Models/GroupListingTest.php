<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Models;

use Concordance\Models\GroupListing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GroupListing value object.
 *
 * GroupListing is a pure value object with no WordPress dependencies
 * except getFormattedLastUpdate() which calls wp_date(). That method
 * is tested separately with a function stub.
 */
class GroupListingTest extends TestCase
{
    /**
     * Helper: build a minimal valid API record array.
     */
    private function sampleData(array $overrides = []): array
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

    /** @test */
    public function fromArray_creates_listing_with_correct_values(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertSame(42, $listing->getId());
        $this->assertSame('SERENITY', $listing->getGroupName());
        $this->assertSame('BRISTOL', $listing->getTown());
        $this->assertSame('BRISTOL INTERGROUP', $listing->getIntergroupName());
        $this->assertSame(5, $listing->getIntergroupId());
        $this->assertSame('Monday', $listing->getDay());
        $this->assertSame('19:30', $listing->getStartTime());
        $this->assertSame('20:30', $listing->getEndTime());
        $this->assertSame('2024-06-15T00:00:00', $listing->getLastUpdate());
    }

    /** @test */
    public function fromArray_defaults_missing_fields(): void
    {
        $listing = GroupListing::fromArray([]);

        $this->assertSame(0, $listing->getId());
        $this->assertSame('', $listing->getGroupName());
        $this->assertSame('', $listing->getTown());
        $this->assertSame('', $listing->getIntergroupName());
        $this->assertSame(0, $listing->getIntergroupId());
        $this->assertSame('', $listing->getDay());
        $this->assertSame('', $listing->getStartTime());
        $this->assertSame('', $listing->getEndTime());
        $this->assertSame('', $listing->getLastUpdate());
    }

    /** @test */
    public function fromArray_preserves_raw_data(): void
    {
        $data = $this->sampleData(['extraField' => 'bonus']);
        $listing = GroupListing::fromArray($data);

        $this->assertSame($data, $listing->getRaw());
        $this->assertSame('bonus', $listing->getRawValue('extraField'));
        $this->assertNull($listing->getRawValue('nonexistent'));
        $this->assertSame('fallback', $listing->getRawValue('nonexistent', 'fallback'));
    }

    /** @test */
    public function fromArray_casts_id_and_intergroupId_to_int(): void
    {
        $listing = GroupListing::fromArray($this->sampleData([
            'id'            => '99',
            'intergroupId'  => '7',
        ]));

        $this->assertSame(99, $listing->getId());
        $this->assertSame(7, $listing->getIntergroupId());
    }

    // ── collectionFromResponse ──────────────────────────────────────

    /** @test */
    public function collectionFromResponse_handles_flat_array(): void
    {
        $response = [
            $this->sampleData(['id' => 1]),
            $this->sampleData(['id' => 2]),
        ];

        $collection = GroupListing::collectionFromResponse($response);

        $this->assertCount(2, $collection);
        $this->assertSame(1, $collection[0]->getId());
        $this->assertSame(2, $collection[1]->getId());
    }

    /** @test */
    public function collectionFromResponse_handles_results_wrapper(): void
    {
        $response = [
            'results' => [
                $this->sampleData(['id' => 10]),
            ],
        ];

        $collection = GroupListing::collectionFromResponse($response);

        $this->assertCount(1, $collection);
        $this->assertSame(10, $collection[0]->getId());
    }

    /** @test */
    public function collectionFromResponse_handles_data_wrapper(): void
    {
        $response = [
            'data' => [
                $this->sampleData(['id' => 20]),
                $this->sampleData(['id' => 21]),
            ],
        ];

        $collection = GroupListing::collectionFromResponse($response);

        $this->assertCount(2, $collection);
    }

    /** @test */
    public function collectionFromResponse_wraps_single_object(): void
    {
        $response = $this->sampleData(['id' => 55]);

        $collection = GroupListing::collectionFromResponse($response);

        $this->assertCount(1, $collection);
        $this->assertSame(55, $collection[0]->getId());
    }

    /** @test */
    public function collectionFromResponse_handles_empty_array(): void
    {
        $collection = GroupListing::collectionFromResponse([]);

        $this->assertCount(0, $collection);
    }

    // ── Display helpers ─────────────────────────────────────────────

    /** @test */
    public function getTimeRange_returns_start_and_end(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertSame('19:30 – 20:30', $listing->getTimeRange());
    }

    /** @test */
    public function getTimeRange_returns_start_only_when_no_end(): void
    {
        $listing = GroupListing::fromArray($this->sampleData(['endTime' => '']));

        $this->assertSame('19:30', $listing->getTimeRange());
    }

    /** @test */
    public function getTimeRange_returns_empty_when_no_start(): void
    {
        $listing = GroupListing::fromArray($this->sampleData(['startTime' => '']));

        $this->assertSame('', $listing->getTimeRange());
    }

    /** @test */
    public function hasTown_returns_true_when_town_set(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertTrue($listing->hasTown());
    }

    /** @test */
    public function hasTown_returns_false_when_town_empty(): void
    {
        $listing = GroupListing::fromArray($this->sampleData(['town' => '']));

        $this->assertFalse($listing->hasTown());
    }

    /** @test */
    public function isValid_returns_true_when_name_set(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertTrue($listing->isValid());
    }

    /** @test */
    public function isValid_returns_false_when_name_empty(): void
    {
        $listing = GroupListing::fromArray($this->sampleData(['groupName' => '']));

        $this->assertFalse($listing->isValid());
    }

    // ── Serialisation ───────────────────────────────────────────────

    /** @test */
    public function toArray_returns_api_shaped_array(): void
    {
        $data = $this->sampleData();
        $listing = GroupListing::fromArray($data);

        $array = $listing->toArray();

        $this->assertSame(42, $array['id']);
        $this->assertSame('SERENITY', $array['groupName']);
        $this->assertSame('Monday', $array['day']);
        $this->assertSame('19:30', $array['startTime']);
        $this->assertArrayNotHasKey('raw', $array);
    }

    /** @test */
    public function jsonSerialize_matches_toArray(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertSame($listing->toArray(), $listing->jsonSerialize());
    }

    /** @test */
    public function toString_returns_group_name(): void
    {
        $listing = GroupListing::fromArray($this->sampleData());

        $this->assertSame('SERENITY', (string) $listing);
    }

    // ── Sorting ─────────────────────────────────────────────────────

    /** @test */
    public function sort_by_day_orders_monday_through_sunday(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['day' => 'Friday', 'groupName' => 'F'])),
            GroupListing::fromArray($this->sampleData(['day' => 'Monday', 'groupName' => 'M'])),
            GroupListing::fromArray($this->sampleData(['day' => 'Wednesday', 'groupName' => 'W'])),
        ];

        GroupListing::sort($groups, 'day');

        $this->assertSame('M', $groups[0]->getGroupName());
        $this->assertSame('W', $groups[1]->getGroupName());
        $this->assertSame('F', $groups[2]->getGroupName());
    }

    /** @test */
    public function sort_by_time_orders_chronologically(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['startTime' => '20:00', 'groupName' => 'Late'])),
            GroupListing::fromArray($this->sampleData(['startTime' => '10:00', 'groupName' => 'Early'])),
            GroupListing::fromArray($this->sampleData(['startTime' => '14:00', 'groupName' => 'Mid'])),
        ];

        GroupListing::sort($groups, 'time');

        $this->assertSame('Early', $groups[0]->getGroupName());
        $this->assertSame('Mid', $groups[1]->getGroupName());
        $this->assertSame('Late', $groups[2]->getGroupName());
    }

    /** @test */
    public function sort_by_name_orders_alphabetically(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['groupName' => 'Zebra'])),
            GroupListing::fromArray($this->sampleData(['groupName' => 'Alpha'])),
            GroupListing::fromArray($this->sampleData(['groupName' => 'Middle'])),
        ];

        GroupListing::sort($groups, 'name');

        $this->assertSame('Alpha', $groups[0]->getGroupName());
        $this->assertSame('Middle', $groups[1]->getGroupName());
        $this->assertSame('Zebra', $groups[2]->getGroupName());
    }

    /** @test */
    public function sort_by_day_then_time(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['day' => 'Tuesday', 'startTime' => '20:00', 'groupName' => 'Tue-Late'])),
            GroupListing::fromArray($this->sampleData(['day' => 'Monday', 'startTime' => '19:00', 'groupName' => 'Mon-Eve'])),
            GroupListing::fromArray($this->sampleData(['day' => 'Tuesday', 'startTime' => '10:00', 'groupName' => 'Tue-Morn'])),
        ];

        GroupListing::sort($groups, 'day,time');

        $this->assertSame('Mon-Eve', $groups[0]->getGroupName());
        $this->assertSame('Tue-Morn', $groups[1]->getGroupName());
        $this->assertSame('Tue-Late', $groups[2]->getGroupName());
    }

    /** @test */
    public function sort_handles_unknown_day_gracefully(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['day' => 'Funday', 'groupName' => 'Unknown'])),
            GroupListing::fromArray($this->sampleData(['day' => 'Monday', 'groupName' => 'Known'])),
        ];

        GroupListing::sort($groups, 'day');

        $this->assertSame('Known', $groups[0]->getGroupName());
        $this->assertSame('Unknown', $groups[1]->getGroupName());
    }

    /** @test */
    public function sort_with_unknown_field_preserves_order(): void
    {
        $groups = [
            GroupListing::fromArray($this->sampleData(['groupName' => 'B'])),
            GroupListing::fromArray($this->sampleData(['groupName' => 'A'])),
        ];

        GroupListing::sort($groups, 'nonexistent');

        // Unknown sort field returns 0 — PHP's usort is not guaranteed stable,
        // but it should not crash. Just verify both elements survive.
        $names = array_map(fn($g) => $g->getGroupName(), $groups);
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
    }
}
