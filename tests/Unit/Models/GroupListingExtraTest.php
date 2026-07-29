<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Models;

use Concordance\Models\GroupListing;
use PHPUnit\Framework\TestCase;

/**
 * Covers GroupListing display helpers not exercised by the main model suite:
 * the formatted last-update date, the title-case normaliser, and the
 * intergroup display name.
 *
 * @covers \Concordance\Models\GroupListing
 */
class GroupListingExtraTest extends TestCase
{
    public function testFormattedLastUpdateEmpty(): void
    {
        $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => '']);
        $this->assertSame('', $g->getFormattedLastUpdate());
    }

    public function testFormattedLastUpdateUnparseableReturnsRaw(): void
    {
        $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => 'not-a-date']);
        $this->assertSame('not-a-date', $g->getFormattedLastUpdate());
    }

    public function testFormattedLastUpdateValid(): void
    {
        $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => '2026-12-25']);
        $this->assertSame('25/12/2026', $g->getFormattedLastUpdate());
    }

    public function testTitleCaseEmpty(): void
    {
        $this->assertSame('', GroupListing::titleCase('   '));
    }

    public function testTitleCasePreservesMixedCase(): void
    {
        $this->assertSame('McDonald AA', GroupListing::titleCase('McDonald AA'));
    }

    public function testTitleCaseNormalisesAllCapsWithSmallWords(): void
    {
        $this->assertSame('Isle of Wight Intergroup', GroupListing::titleCase('ISLE OF WIGHT INTERGROUP'));
    }

    public function testTitleCaseCapitalisesLeadingSmallWord(): void
    {
        // A small connector word first still gets capitalised.
        $this->assertSame('The Group', GroupListing::titleCase('THE GROUP'));
    }

    public function testIntergroupDisplayName(): void
    {
        $g = GroupListing::fromArray(['groupName' => 'X', 'intergroupName' => 'WILTSHIRE INTERGROUP']);
        $this->assertSame('Wiltshire Intergroup', $g->getIntergroupDisplayName());
    }
}
