<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Admin\GroupListings;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Admin\GroupListings\GroupListingDashboard;
use Concordance\Api\ApiCache;
use Concordance\Common\ConcordanceConfiguration;
use ReflectionMethod;
use WP_Error;

/**
 * Tests for the "National Group Listings" dashboard widget.
 *
 * Companion to SettingsAdminTest — see that class for why src/Admin stopped
 * being excluded from coverage. The techniques divide the same way:
 *
 *   - registerDashboardWidget() runs for real against WpState::$widgets.
 *   - renderDashboardWidget() and its private helpers are driven inside an
 *     output buffer, which is what proves the ApiCache wiring: an errored
 *     response, an empty one, and a filtered one each produce different HTML.
 *   - handleSetIntergroup()'s two guards call wp_die(), which the stubs turn
 *     into a WpDieException, so each is a plain expectException. Its tail
 *     redirects and then exits, so the option write and referer resolution
 *     were split into applySetIntergroup() and are reached by reflection.
 *   - ajaxFilterIntergroup() needs none of that: wp_send_json_success/error
 *     throw a JsonResponseException, so every branch including the happy path
 *     is directly assertable.
 *
 * No HTTP happens here — ApiCache is a double throughout.
 *
 * @covers \Concordance\Admin\GroupListings\GroupListingDashboard
 */
class GroupListingDashboardTest extends TestCase
{
    /** @var ApiCache&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    private GroupListingDashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];

        $this->cache     = $this->createMock(ApiCache::class);
        $this->dashboard = new GroupListingDashboard($this->cache);
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function the_constructor_registers_every_hook_the_widget_needs(): void
    {
        foreach ([
            'wp_dashboard_setup',
            'admin_head',
            'admin_post_concordance_set_intergroup',
            'wp_ajax_concordance_filter_intergroup',
        ] as $hook) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }
    }

    /** @test */
    public function the_widget_registers_itself_on_the_dashboard(): void
    {
        $this->dashboard->registerDashboardWidget();

        $this->assertArrayHasKey('concordance_group_listings_dashboard', WpState::$widgets);
        $this->assertSame(
            'National Group Listings',
            WpState::$widgets['concordance_group_listings_dashboard']['name']
        );
        $this->assertSame(
            [$this->dashboard, 'renderDashboardWidget'],
            WpState::$widgets['concordance_group_listings_dashboard']['callback']
        );
    }

    // ── widget rendering ──────────────────────────────────────────────

    /** @test */
    public function an_api_error_is_shown_in_place_of_the_widget(): void
    {
        $this->cache->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $html = $this->renderWidget();

        $this->assertStringContainsString('gl-error', $html);
        $this->assertStringContainsString('Connection refused', $html);
        $this->assertStringNotContainsString('gl-card', $html);
    }

    /** @test */
    public function an_empty_api_response_says_so(): void
    {
        $this->cache->method('getGroups')->willReturn([]);

        $html = $this->renderWidget();

        $this->assertStringContainsString('No groups found from the AAGBDB API.', $html);
        $this->assertStringNotContainsString('gl-cards', $html);
    }

    /** @test */
    public function every_group_gets_a_card_when_no_filter_is_set(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertSame(3, substr_count($html, 'class="gl-card"'));
        $this->assertStringContainsString('Monday Nooners', $html);
        $this->assertStringContainsString('Tuesday Steps', $html);
        $this->assertStringContainsString('Wednesday Big Book', $html);
        $this->assertStringContainsString('>3</span>', $html, 'the count should match the cards');
    }

    /**
     * Cards are ordered day, then time, then name — the order someone
     * scanning the week expects, not the order the API happened to return.
     *
     * @test
     */
    public function cards_are_sorted_by_day_then_time(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertLessThan(strpos($html, 'Tuesday Steps'), strpos($html, 'Monday Nooners'));
        $this->assertLessThan(strpos($html, 'Wednesday Big Book'), strpos($html, 'Tuesday Steps'));
    }

    /** @test */
    public function the_saved_filter_narrows_the_cards_but_not_the_dropdown(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 7;
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertSame(2, substr_count($html, 'class="gl-card"'));
        $this->assertStringNotContainsString('Monday Nooners', $html, 'that group is in intergroup 9');
        // Both intergroups still appear as choices.
        $this->assertStringContainsString('<option value="7" selected>Bristol</option>', $html);
        $this->assertStringContainsString('<option value="9">Cornwall</option>', $html);
    }

    /** @test */
    public function a_filter_matching_nothing_explains_itself_rather_than_rendering_blank(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 999;
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertStringNotContainsString('class="gl-card"', $html);
        $this->assertStringContainsString('No groups match the selected intergroup', $html);
        // The now-unknown saved id is kept in the dropdown so it isn't dropped.
        $this->assertStringContainsString('Intergroup #999 (currently saved)', $html);
    }

    /** @test */
    public function the_selector_posts_to_admin_post_with_a_nonce_and_a_no_js_fallback(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertStringContainsString('action="https://example.test/wp-admin/admin-post.php"', $html);
        $this->assertStringContainsString('value="concordance_set_intergroup"', $html);
        $this->assertStringContainsString('name="_concordance_nonce"', $html);
        $this->assertStringContainsString('<noscript>', $html);
    }

    /**
     * The inline script's element lookups run against markup emitted before
     * it, so the script must come last or it silently binds nothing.
     *
     * @test
     */
    public function the_inline_script_is_emitted_after_the_cards_container(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertLessThan(
            strpos($html, '<script>'),
            strpos($html, 'data-concordance-cards'),
            'the cards container must exist before the script that looks it up'
        );
        $this->assertStringContainsString('concordance_filter_intergroup', $html);
        $this->assertStringContainsString('"https:\/\/example.test\/wp-admin\/admin-ajax.php"', $html);
    }

    /** @test */
    public function an_intergroup_with_no_id_is_left_out_of_the_dropdown(): void
    {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 0, 'intergroupName' => 'Unassigned'],
            ['id' => 2, 'groupName' => 'B', 'intergroupId' => 5, 'intergroupName' => ''],
        ]);

        $html = $this->renderWidget();

        $this->assertStringNotContainsString('Unassigned', $html);
        $this->assertStringContainsString('<option value="5">Intergroup #5</option>', $html);
    }

    // ── card contents ─────────────────────────────────────────────────

    /** @test */
    public function only_the_enabled_fields_appear_on_a_card(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['town'];
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertStringContainsString('>Town</div>', $html);
        $this->assertStringNotContainsString('>Day</div>', $html);
        $this->assertStringNotContainsString('>Start Time</div>', $html);
    }

    /** @test */
    public function fields_are_rendered_in_whitelist_order_not_submission_order(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['postcode', 'day'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'day' => 'Monday', 'postcode' => 'BS1 1AA'],
        ]);

        $html = $this->renderWidget();

        $this->assertLessThan(strpos($html, '>Postcode</div>'), strpos($html, '>Day</div>'));
    }

    /** @test */
    public function a_corrupted_fields_option_falls_back_to_the_defaults(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = 'corrupted';
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->renderWidget();

        $this->assertStringContainsString('>Day</div>', $html);
        $this->assertStringContainsString('>Town</div>', $html);
    }

    /**
     * An enabled-but-unusable field would otherwise render as an empty labelled
     * row on every card.
     *
     * @test
     * @dataProvider skippedValues
     */
    public function unusable_field_values_are_skipped(mixed $value): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'notes' => $value],
        ]);

        $html = $this->renderWidget();

        $this->assertStringNotContainsString('gl-card-content', $html);
        $this->assertStringContainsString('<strong>A</strong>', $html, 'the card itself should still render');
    }

    /** @return array<string, array{0: mixed}> */
    public static function skippedValues(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
            'false'        => [false],
            'a nested list' => [['a', 'b']],
            'an object'    => [(object) ['a' => 'b']],
        ];
    }

    /** @test */
    public function a_field_absent_from_the_api_payload_is_skipped(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes', 'town'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'town' => 'BRISTOL'],
        ]);

        $html = $this->renderWidget();

        $this->assertStringContainsString('>Town</div>', $html);
        $this->assertStringNotContainsString('>Notes</div>', $html);
    }

    /** @test */
    public function a_true_flag_renders_as_yes(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['wheelchair'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'wheelchair' => true],
        ]);

        $html = $this->renderWidget();

        $this->assertStringContainsString('>Wheelchair Accessible</div>', $html);
        $this->assertStringContainsString('>Yes</div>', $html);
    }

    /** @test */
    public function a_url_field_becomes_a_new_tab_link(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'notes' => 'https://example.test/hall'],
        ]);

        $html = $this->renderWidget();

        $this->assertStringContainsString(
            '<a href="https://example.test/hall" target="_blank" rel="noopener">',
            $html
        );
    }

    /** @test */
    public function an_email_field_becomes_a_mailto_link(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['regionHelpline'];
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'regionHelpline' => 'help@example.test'],
        ]);

        $this->assertStringContainsString(
            '<a href="mailto:help@example.test">',
            $this->renderWidget()
        );
    }

    /** @test */
    public function a_long_value_gets_the_full_width_class(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['notes', 'town'];
        $this->cache->method('getGroups')->willReturn([
            [
                'id' => 1, 'groupName' => 'A', 'town' => 'BRISTOL',
                'notes' => str_repeat('a', 81),
            ],
        ]);

        $html = $this->renderWidget();

        $this->assertStringContainsString('class="gl-card-field gl-card-field-full"', $html);
        $this->assertStringContainsString('class="gl-card-field"', $html, 'the short field stays half-width');
    }

    /**
     * @test
     * @dataProvider nameKeys
     * @param array<string, mixed> $raw
     */
    public function the_card_title_falls_back_through_the_api_name_keys(array $raw, string $expected): void
    {
        $this->cache->method('getGroups')->willReturn([['id' => 1] + $raw]);

        $this->assertStringContainsString(
            '<strong>' . $expected . '</strong>',
            $this->renderWidget()
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function nameKeys(): array
    {
        return [
            'groupName'      => [['groupName' => 'From groupName'], 'From groupName'],
            'name'           => [['name' => 'From name'], 'From name'],
            'title'          => [['title' => 'From title'], 'From title'],
            'groupName wins' => [['groupName' => 'First', 'name' => 'Second'], 'First'],
            'nothing usable' => [[], 'Unknown Group'],
        ];
    }

    // ── admin styles ──────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider screens
     */
    public function the_widget_styles_load_on_the_dashboard_only(?string $screenId, bool $expected): void
    {
        WpState::$screen = $screenId === null ? null : (object) ['id' => $screenId];

        $html = $this->render([$this->dashboard, 'addDashboardStyles']);

        if ($expected) {
            $this->assertStringContainsString('.gl-dashboard-widget', $html);
        } else {
            $this->assertSame('', $html);
        }
    }

    /** @return array<string, array{0: string|null, 1: bool}> */
    public static function screens(): array
    {
        return [
            'no screen yet'   => [null, false],
            'the post editor' => ['edit-post', false],
            'the dashboard'   => ['dashboard', true],
        ];
    }

    // ── the admin-post handler ────────────────────────────────────────

    /** @test */
    public function the_filter_form_refuses_a_user_without_either_capability(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->dashboard->handleSetIntergroup();
    }

    /** @test */
    public function the_filter_form_refuses_a_forged_nonce(): void
    {
        $_POST = ['_concordance_nonce' => 'forged', 'intergroup_id' => '7'];

        try {
            $this->dashboard->handleSetIntergroup();
            $this->fail('expected wp_die() to be called');
        } catch (WpDieException $e) {
            $this->assertArrayNotHasKey(
                ConcordanceConfiguration::OPTION_INTERGROUP_ID,
                WpState::$options,
                'a rejected request must not write the option'
            );
        }
    }

    /** @test */
    public function a_missing_nonce_is_treated_as_a_forged_one(): void
    {
        $_POST = ['intergroup_id' => '7'];

        $this->expectException(WpDieException::class);
        $this->dashboard->handleSetIntergroup();
    }

    /**
     * @test
     * @dataProvider submittedFilters
     */
    public function the_submitted_filter_is_sanitised_and_saved(mixed $submitted, int $expected): void
    {
        $_POST = ['intergroup_id' => $submitted];

        $this->applySetIntergroup();

        $this->assertSame(
            $expected,
            WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID]
        );
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function submittedFilters(): array
    {
        return [
            'a numeric string' => ['7', 7],
            'the all sentinel' => ['0', 0],
            'a negative value' => ['-7', 7],
            'not a number'     => ['nonsense', 0],
        ];
    }

    /** @test */
    public function an_absent_filter_value_saves_the_all_sentinel(): void
    {
        $this->applySetIntergroup();

        $this->assertSame(
            ConcordanceConfiguration::INTERGROUP_ID_ALL,
            WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID]
        );
    }

    /** @test */
    public function the_handler_returns_to_the_posted_referer(): void
    {
        $_POST = ['_wp_http_referer' => 'https://example.test/wp-admin/index.php?page=2'];

        $this->assertSame(
            'https://example.test/wp-admin/index.php?page=2',
            $this->applySetIntergroup()
        );
    }

    /** @test */
    public function the_handler_falls_back_to_the_dashboard_without_a_referer(): void
    {
        $this->assertSame(
            'https://example.test/wp-admin/index.php',
            $this->applySetIntergroup()
        );
    }

    // ── the AJAX endpoint ─────────────────────────────────────────────

    /** @test */
    public function the_ajax_endpoint_refuses_a_user_without_either_capability(): void
    {
        WpState::$userCan = false;

        $error = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertFalse($error->success);
        $this->assertSame(403, $error->status);
    }

    /** @test */
    public function the_ajax_endpoint_refuses_a_forged_nonce(): void
    {
        $_POST = ['_concordance_nonce' => 'forged'];

        $error = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertFalse($error->success);
        $this->assertSame(403, $error->status);
        $this->assertArrayNotHasKey(
            ConcordanceConfiguration::OPTION_INTERGROUP_ID,
            WpState::$options,
            'a rejected request must not write the option'
        );
    }

    /** @test */
    public function the_ajax_endpoint_refuses_a_request_with_no_nonce_at_all(): void
    {
        $error = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertFalse($error->success);
        $this->assertSame(403, $error->status);
    }

    /** @test */
    public function the_ajax_endpoint_reports_an_api_error_as_a_server_error(): void
    {
        $this->postFilter(7);
        $this->cache->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $error = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertFalse($error->success);
        $this->assertSame(500, $error->status);
        $this->assertSame(['message' => 'Connection refused'], $error->data);
    }

    /** @test */
    public function the_ajax_endpoint_saves_the_filter_and_returns_the_matching_cards(): void
    {
        $this->postFilter(7);
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $success = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertTrue($success->success);
        $this->assertSame(7, WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID]);
        $this->assertSame(2, $success->data['count']);
        $this->assertSame(2, substr_count($success->data['html'], 'class="gl-card"'));
        $this->assertStringNotContainsString('Monday Nooners', $success->data['html']);
    }

    /** @test */
    public function the_ajax_endpoint_returns_every_card_for_the_all_sentinel(): void
    {
        $this->postFilter(ConcordanceConfiguration::INTERGROUP_ID_ALL);
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $success = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertSame(3, $success->data['count']);
    }

    /**
     * The swapped-in region is the cards only — re-rendering the selector too
     * would nest a second form inside the first.
     *
     * @test
     */
    public function the_ajax_payload_carries_the_cards_without_the_selector(): void
    {
        $this->postFilter(ConcordanceConfiguration::INTERGROUP_ID_ALL);
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup())->data['html'];

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    /** @test */
    public function an_ajax_filter_matching_nothing_returns_the_empty_message(): void
    {
        $this->postFilter(999);
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $success = $this->catchJson(fn () => $this->dashboard->ajaxFilterIntergroup());

        $this->assertSame(0, $success->data['count']);
        $this->assertStringContainsString('No groups match the selected intergroup', $success->data['html']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** Mark the current request as a nonce-verified filter submission. */
    private function postFilter(int $intergroupId): void
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
    private function applySetIntergroup(): string
    {
        $method = new ReflectionMethod(GroupListingDashboard::class, 'applySetIntergroup');

        return (string) $method->invoke($this->dashboard);
    }

    private function catchJson(callable $callback): JsonResponseException
    {
        try {
            $callback();
        } catch (JsonResponseException $e) {
            return $e;
        }

        $this->fail('expected a JSON response to be sent');
    }

    private function renderWidget(): string
    {
        return $this->render([$this->dashboard, 'renderDashboardWidget']);
    }

    private function render(callable $callback): string
    {
        ob_start();
        try {
            $callback();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Three groups across two intergroups, listed out of day order so sorting
     * is observable, with the alphabetically later intergroup first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupsResponse(): array
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
}
