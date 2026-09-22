<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Models;

use Concordance\Models\GroupListing;

/*
 * Covers GroupListing display helpers not exercised by the main model suite:
 * the formatted last-update date, the title-case normaliser, and the
 * intergroup display name.
 */

covers(\Concordance\Models\GroupListing::class);

it('formats an empty last update as an empty string', function () {
    $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => '']);
    expect($g->getFormattedLastUpdate())->toBe('');
});

it('returns an unparseable last update unchanged', function () {
    $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => 'not-a-date']);
    expect($g->getFormattedLastUpdate())->toBe('not-a-date');
});

it('formats a valid last update as DD/MM/YYYY', function () {
    $g = GroupListing::fromArray(['groupName' => 'X', 'lastUpdate' => '2026-12-25']);
    expect($g->getFormattedLastUpdate())->toBe('25/12/2026');
});

it('title-cases blank input to an empty string', function () {
    expect(GroupListing::titleCase('   '))->toBe('');
});

it('preserves mixed case when title-casing', function () {
    expect(GroupListing::titleCase('McDonald AA'))->toBe('McDonald AA');
});

it('normalises all caps with small words when title-casing', function () {
    expect(GroupListing::titleCase('ISLE OF WIGHT INTERGROUP'))->toBe('Isle of Wight Intergroup');
});

it('capitalises a leading small word when title-casing', function () {
    // A small connector word first still gets capitalised.
    expect(GroupListing::titleCase('THE GROUP'))->toBe('The Group');
});

it('title-cases the intergroup display name', function () {
    $g = GroupListing::fromArray(['groupName' => 'X', 'intergroupName' => 'WILTSHIRE INTERGROUP']);
    expect($g->getIntergroupDisplayName())->toBe('Wiltshire Intergroup');
});
