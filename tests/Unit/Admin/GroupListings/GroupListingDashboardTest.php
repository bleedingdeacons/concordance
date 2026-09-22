<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Admin\GroupListings;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Admin\GroupListings\GroupListingDashboard;
use Concordance\Api\ApiCache;
use Concordance\Common\ConcordanceConfiguration;
use ReflectionMethod;
use WP_Error;

/*
 * Tests for the "National Group Listings" dashboard widget.
 *
 * Companion to SettingsAdminTest — see that file for why src/Admin stopped
 * being excluded from coverage. The techniques divide the same way:
 *
 *   - registerDashboardWidget() runs for real against WpState::$widgets.
 *   - renderDashboardWidget() and its private helpers are driven inside an
 *     output buffer, which is what proves the ApiCache wiring: an errored
 *     response, an empty one, and a filtered one each produce different HTML.
 *   - handleSetIntergroup()'s two guards call wp_die(), which the stubs turn
 *     into a WpDieException, so each is a plain ->throws(). Its tail
 *     redirects and then exits, so the option write and referer resolution
 *     were split into applySetIntergroup() and are reached by reflection.
 *   - ajaxFilterIntergroup() needs none of that: wp_send_json_success/error
 *     throw a JsonResponseException, so every branch including the happy path
 *     is directly assertable.
 *
 * No HTTP happens here — ApiCache is a double throughout.
 */

covers(\Concordance\Admin\GroupListings\GroupListingDashboard::class);

/** Mark the current request as a nonce-verified filter submission. */
function postDashboardFilter(int $intergroupId): void
{
    $_POST = [
        '_concordance_nonce' => 'nonce-concordance_set_intergroup',
        'intergroup_id'      => (string) $intergroupId,
    ];
}

/**
 * Invoke the branch of handleSetIntergroup() that would otherwise be
 * followed by exit().
 */
function applyDashboardSetIntergroup(GroupListingDashboard $dashboard): string
{
    $method = new ReflectionMethod(GroupListingDashboard::class, 'applySetIntergroup');

    return (string) $method->invoke($dashboard);
}

/**
 * Three groups across two intergroups, listed out of day order so sorting
 * is observable, with the alphabetically later intergroup first.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardGroupsResponse(): array
{
    return [
        [
            'id' => 2, 'groupName' => 'Tuesday Steps', 'town' => 'BATH',
            'intergroupId' => 7, 'intergroupName' => 'BRISTOL',
            'day' => 'Tuesday', 'startTime' => '19:30', 'endTime' => '21:00',
        ],
        [
            'id' => 1, 'groupName' => 'Monday Nooners', 'town' => 'BRISTOL',
            'intergroupId' => 9, 'intergroupName' => 'CORNWALL',
            'day' => 'Monday', 'startTime' => '12:00', 'endTime' => '13:00',
        ],
        [
            'id' => 3, 'groupName' => 'Wednesday Big Book', 'town' => 'WELLS',
            'intergroupId' => 7, 'intergroupName' => 'BRISTOL',
            'day' => 'Wednesday', 'startTime' => '18:00', 'endTime' => '19:30',
        ],
    ];
}

beforeEach(function () {
    $_POST = [];

    $this->cache     = $this->createMock(ApiCache::class);
    $this->dashboard = new GroupListingDashboard($this->cache);

    $this->renderWidget = fn (): string => captureOutput([$this->dashboard, 'renderDashboardWidget']);

    $this->catchJson = function (callable $callback): JsonResponseException {
        try {
            $callback();
        } catch (JsonResponseException $e) {
            return $e;
        }

        $this->fail('expected a JSON response to be sent');
    };
});

afterEach(function () {
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers every hook the widget needs from the constructor', function () {
        foreach (
            [
            'wp_dashboard_setup',
            'admin_head',
            'admin_post_concordance_set_intergroup',
            'wp_ajax_concordance_filter_intergroup',
            ] as $hook
        ) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }
    });

    it('registers the widget on the dashboard', function () {
        $this->dashboard->registerDashboardWidget();

        expect(WpState::$widgets)->toHaveKey('concordance_group_listings_dashboard')
            ->and(WpState::$widgets['concordance_group_listings_dashboard']['name'])
            ->toBe('National Group Listings')
            ->and(WpState::$widgets['concordance_group_listings_dashboard']['callback'])
            ->toBe([$this->dashboard, 'renderDashboardWidget']);
    });
});

// ── widget rendering ──────────────────────────────────────────────
describe('widget rendering', function () {
    it('shows an API error in place of the widget', function () {
        $this->cache->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $html = ($this->renderWidget)();

        expect($html)->toContain('gl-error')
            ->toContain('Connection refused')
            ->not->toContain('gl-card');
    });

    it('says so when the API response is empty', function () {
        $this->cache->method('getGroups')->willReturn([]);

        $html = ($this->renderWidget)();

        expect($html)->toContain('No groups found from the AAGBDB API.')
            ->not->toContain('gl-cards');
    });

    it('gives every group a card when no filter is set', function () {
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect(substr_count($html, 'class="gl-card"'))->toBe(3)
            ->and($html)->toContain('Monday Nooners')
            ->toContain('Tuesday Steps')
            ->toContain('Wednesday Big Book')
            ->and(str_contains($html, '>3</span>'))->toBeTrue('the count should match the cards');
    });

    // Cards are ordered day, then time, then name — the order someone
    // scanning the week expects, not the order the API happened to return.
    it('sorts the cards by day then time', function () {
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect(strpos($html, 'Monday Nooners'))->toBeLessThan(strpos($html, 'Tuesday Steps'))
            ->and(strpos($html, 'Tuesday Steps'))->toBeLessThan(strpos($html, 'Wednesday Big Book'));
    });

    it('narrows the cards but not the dropdown with the saved filter', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 7;
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect(substr_count($html, 'class="gl-card"'))->toBe(2)
            ->and(str_contains($html, 'Monday Nooners'))->toBeFalse('that group is in intergroup 9')
            // Both intergroups still appear as choices.
            ->and($html)->toContain('<option value="7" selected>Bristol</option>')
            ->toContain('<option value="9">Cornwall</option>');
    });

    it('explains a filter matching nothing rather than rendering blank', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 999;
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect($html)->not->toContain('class="gl-card"')
            ->toContain('No groups match the selected intergroup')
            // The now-unknown saved id is kept in the dropdown so it isn't dropped.
            ->toContain('Intergroup #999 (currently saved)');
    });

    it('posts the selector to admin-post with a nonce and a no-JS fallback', function () {
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect($html)->toContain('action="https://example.test/wp-admin/admin-post.php"')
            ->toContain('value="concordance_set_intergroup"')
            ->toContain('name="_concordance_nonce"')
            ->toContain('<noscript>');
    });

    // The inline script's element lookups run against markup emitted before
    // it, so the script must come last or it silently binds nothing.
    it('emits the inline script after the cards container', function () {
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect(strpos($html, 'data-concordance-cards'))->toBeLessThan(
            strpos($html, '<script>'),
            'the cards container must exist before the script that looks it up'
        )
            ->and($html)->toContain('concordance_filter_intergroup')
            ->toContain('"https:\/\/example.test\/wp-admin\/admin-ajax.php"');
    });

    it('leaves an intergroup with no id out of the dropdown', function () {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 0, 'intergroupName' => 'Unassigned'],
            ['id' => 2, 'groupName' => 'B', 'intergroupId' => 5, 'intergroupName' => ''],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->not->toContain('Unassigned')
            ->toContain('<option value="5">Intergroup #5</option>');
    });
});

// ── card contents ─────────────────────────────────────────────────
describe('card contents', function () {
    it('shows only the enabled fields on a card', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['town'];
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect($html)->toContain('>Town</div>')
            ->not->toContain('>Day</div>')
            ->not->toContain('>Start Time</div>');
    });

    it('renders fields in whitelist order, not submission order', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['postcode', 'day'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'day' => 'Monday', 'postcode' => 'BS1 1AA'],
        ]);

        $html = ($this->renderWidget)();

        expect(strpos($html, '>Day</div>'))->toBeLessThan(strpos($html, '>Postcode</div>'));
    });

    it('falls back to the defaults for a corrupted fields option', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = 'corrupted';
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->renderWidget)();

        expect($html)->toContain('>Day</div>')
            ->toContain('>Town</div>');
    });

    // An enabled-but-unusable field would otherwise render as an empty labelled
    // row on every card.
    it('skips unusable field values', function (mixed $value) {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'notes' => $value],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->not->toContain('gl-card-content')
            ->and(str_contains($html, '<strong>A</strong>'))->toBeTrue('the card itself should still render');
    })->with([
        'null'          => [null],
        'empty string'  => [''],
        'false'         => [false],
        'a nested list' => [['a', 'b']],
        'an object'     => [(object) ['a' => 'b']],
    ]);

    it('skips a field absent from the API payload', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes', 'town'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'town' => 'BRISTOL'],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->toContain('>Town</div>')
            ->not->toContain('>Notes</div>');
    });

    it('renders a true flag as Yes', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['wheelchair'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'wheelchair' => true],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->toContain('>Wheelchair Accessible</div>')
            ->toContain('>Yes</div>');
    });

    it('turns a URL field into a new-tab link', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'notes' => 'https://example.test/hall'],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->toContain('<a href="https://example.test/hall" target="_blank" rel="noopener">');
    });

    it('turns an email field into a mailto link', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['regionHelpline'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'regionHelpline' => 'help@example.test'],
        ]);

        expect(($this->renderWidget)())->toContain('<a href="mailto:help@example.test">');
    });

    it('gives a long value the full-width class', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes', 'town'];
        $this->cache->method('getGroups')->willReturn([
            [
                'id' => 1, 'groupName' => 'A', 'town' => 'BRISTOL',
                'notes' => str_repeat('a', 81),
            ],
        ]);

        $html = ($this->renderWidget)();

        expect($html)->toContain('class="gl-card-field gl-card-field-full"')
            ->and(str_contains($html, 'class="gl-card-field"'))->toBeTrue('the short field stays half-width');
    });

    it('falls back through the API name keys for the card title', function (array $raw, string $expected) {
        $this->cache->method('getGroups')->willReturn([['id' => 1] + $raw]);

        expect(($this->renderWidget)())->toContain('<strong>' . $expected . '</strong>');
    })->with([
        'groupName'      => [['groupName' => 'From groupName'], 'From groupName'],
        'name'           => [['name' => 'From name'], 'From name'],
        'title'          => [['title' => 'From title'], 'From title'],
        'groupName wins' => [['groupName' => 'First', 'name' => 'Second'], 'First'],
        'nothing usable' => [[], 'Unknown Group'],
    ]);
});

// ── admin styles ──────────────────────────────────────────────────
it('loads the widget styles on the dashboard only', function (?string $screenId, bool $expected) {
    WpState::$screen = $screenId === null ? null : (object) ['id' => $screenId];

    $html = captureOutput([$this->dashboard, 'addDashboardStyles']);

    if ($expected) {
        expect($html)->toContain('.gl-dashboard-widget');
    } else {
        expect($html)->toBe('');
    }
})->with([
    'no screen yet'   => [null, false],
    'the post editor' => ['edit-post', false],
    'the dashboard'   => ['dashboard', true],
]);

// ── the admin-post handler ────────────────────────────────────────
describe('the admin-post handler', function () {
    it('refuses a user without either capability', function () {
        WpState::$userCan = false;

        $this->dashboard->handleSetIntergroup();
    })->throws(WpDieException::class);

    it('refuses a forged nonce', function () {
        $_POST = ['_concordance_nonce' => 'forged', 'intergroup_id' => '7'];

        expect(fn () => $this->dashboard->handleSetIntergroup())->toThrow(WpDieException::class)
            ->and(WpState::$options)->not->toHaveKey(
                ConcordanceConfiguration::OPTION_INTERGROUP_ID,
                message: 'a rejected request must not write the option'
            );
    });

    it('treats a missing nonce as a forged one', function () {
        $_POST = ['intergroup_id' => '7'];

        $this->dashboard->handleSetIntergroup();
    })->throws(WpDieException::class);

    it('sanitises and saves the submitted filter', function (mixed $submitted, int $expected) {
        $_POST = ['intergroup_id' => $submitted];

        applyDashboardSetIntergroup($this->dashboard);

        expect(WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID])->toBe($expected);
    })->with([
        'a numeric string' => ['7', 7],
        'the all sentinel' => ['0', 0],
        'a negative value' => ['-7', 7],
        'not a number'     => ['nonsense', 0],
    ]);

    it('saves the all sentinel for an absent filter value', function () {
        applyDashboardSetIntergroup($this->dashboard);

        expect(WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID])
            ->toBe(ConcordanceConfiguration::INTERGROUP_ID_ALL);
    });

    it('returns to the posted referer', function () {
        $_POST = ['_wp_http_referer' => 'https://example.test/wp-admin/index.php?page=2'];

        expect(applyDashboardSetIntergroup($this->dashboard))
            ->toBe('https://example.test/wp-admin/index.php?page=2');
    });

    it('falls back to the dashboard without a referer', function () {
        expect(applyDashboardSetIntergroup($this->dashboard))
            ->toBe('https://example.test/wp-admin/index.php');
    });
});

// ── the AJAX endpoint ─────────────────────────────────────────────
describe('the AJAX endpoint', function () {
    it('refuses a user without either capability', function () {
        WpState::$userCan = false;

        $error = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($error->success)->toBeFalse()
            ->and($error->status)->toBe(403);
    });

    it('refuses a forged nonce', function () {
        $_POST = ['_concordance_nonce' => 'forged'];

        $error = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($error->success)->toBeFalse()
            ->and($error->status)->toBe(403)
            ->and(WpState::$options)->not->toHaveKey(
                ConcordanceConfiguration::OPTION_INTERGROUP_ID,
                message: 'a rejected request must not write the option'
            );
    });

    it('refuses a request with no nonce at all', function () {
        $error = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($error->success)->toBeFalse()
            ->and($error->status)->toBe(403);
    });

    it('reports an API error as a server error', function () {
        postDashboardFilter(7);
        $this->cache->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $error = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($error->success)->toBeFalse()
            ->and($error->status)->toBe(500)
            ->and($error->data)->toBe(['message' => 'Connection refused']);
    });

    it('saves the filter and returns the matching cards', function () {
        postDashboardFilter(7);
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $success = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($success->success)->toBeTrue()
            ->and(WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID])->toBe(7)
            ->and($success->data['count'])->toBe(2)
            ->and(substr_count($success->data['html'], 'class="gl-card"'))->toBe(2)
            ->and($success->data['html'])->not->toContain('Monday Nooners');
    });

    it('returns every card for the all sentinel', function () {
        postDashboardFilter(ConcordanceConfiguration::INTERGROUP_ID_ALL);
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $success = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($success->data['count'])->toBe(3);
    });

    // The swapped-in region is the cards only — re-rendering the selector too
    // would nest a second form inside the first.
    it('carries the cards without the selector in its payload', function () {
        postDashboardFilter(ConcordanceConfiguration::INTERGROUP_ID_ALL);
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $html = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup())->data['html'];

        expect($html)->not->toContain('<form')
            ->not->toContain('<select');
    });

    it('returns the empty message for a filter matching nothing', function () {
        postDashboardFilter(999);
        $this->cache->method('getGroups')->willReturn(dashboardGroupsResponse());

        $success = ($this->catchJson)(fn () => $this->dashboard->ajaxFilterIntergroup());

        expect($success->data['count'])->toBe(0)
            ->and($success->data['html'])->toContain('No groups match the selected intergroup');
    });
});
