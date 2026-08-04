<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Concordance\Admin\SettingsAdmin;
use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use Concordance\Common\ConcordanceConfiguration;
use Concordance\Common\Encryption;
use ReflectionMethod;
use RuntimeException;
use WP_Error;

/**
 * Tests for the Concordance settings screen.
 *
 * src/Admin was excluded from the coverage source set until now, on the
 * grounds that admin screens are "WordPress admin render/menu/AJAX glue
 * exercised through the admin UI at runtime". Amber covers its whole src/Admin
 * on the same tooling, and Integrity's SettingsPageTest ported the pattern, so
 * the exclusion was habit rather than necessity.
 *
 * Three kinds of method, three techniques:
 *
 *   - Registration (the constructor's hooks, registerMenu, registerSettings)
 *     runs for real and is asserted against WpState, which records menu pages,
 *     and against captures of the Settings API calls, which wp-mocks does not
 *     stub (register_setting, add_settings_section, add_settings_field).
 *   - Render methods are called inside an output buffer and asserted on their
 *     HTML. That is what proves the API-client wiring behind them — the
 *     intergroup dropdown is built from whatever ApiCache has already
 *     returned, and the API key field round-trips through Encryption.
 *   - handleCacheFlush() ends in wp_safe_redirect() followed by a bare exit.
 *     wp_safe_redirect is recorded rather than thrown, so exit would run and
 *     take PHPUnit with it. Its branching now lives in
 *     resolveCacheFlushRedirect(), reached here through reflection — the same
 *     extraction Integrity used for parsePermissions()/parseIpWhitelist().
 *
 * Nothing here touches the network: ApiClient and ApiCache are both doubles,
 * and no real credential is ever stored or printed.
 *
 * @covers \Concordance\Admin\SettingsAdmin
 */
class SettingsAdminTest extends TestCase
{
    /** @var ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;

    /** @var ApiCache&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    private SettingsAdmin $admin;

    /**
     * Settings API calls captured from registerSettings().
     *
     * @var array<string, array{group: string, args: array<string, mixed>}>
     */
    private array $settings = [];

    /** @var array<int, array{id: string, page: string}> */
    private array $sections = [];

    /** @var array<int, array{id: string, page: string, section: string, callback: mixed}> */
    private array $fields = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];

        $this->stubSettingsApi();

        $this->client = $this->createMock(ApiClient::class);
        $this->cache  = $this->createMock(ApiCache::class);
        $this->admin  = new SettingsAdmin($this->client, new Encryption(), $this->cache);
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    /**
     * The Settings API and a handful of admin-page helpers are outside what
     * wp-mocks stubs, so they are defined here. The three registration
     * functions record rather than discard, which is what the registration
     * tests below assert on.
     */
    private function stubSettingsApi(): void
    {
        Functions\when('register_setting')->alias(
            function (string $group, string $name, array $args = []): void {
                $this->settings[$name] = ['group' => $group, 'args' => $args];
            }
        );

        Functions\when('add_settings_section')->alias(
            function (string $id, string $title, mixed $callback, string $page): void {
                $this->sections[] = ['id' => $id, 'page' => $page];
                if (is_callable($callback)) {
                    ob_start();
                    $callback();
                    ob_end_clean();
                }
            }
        );

        Functions\when('add_settings_field')->alias(
            function (string $id, string $title, mixed $callback, string $page, string $section = ''): void {
                $this->fields[] = [
                    'id' => $id, 'page' => $page, 'section' => $section, 'callback' => $callback,
                ];
            }
        );

        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->alias(static function (string $text = 'Save'): void {
            echo '<button type="submit">' . $text . '</button>';
        });
        Functions\when('get_admin_page_title')->justReturn('Concordance');
        Functions\when('wp_nonce_url')->alias(
            static fn (string $url, string $action = ''): string => $url . '&_wpnonce=nonce-' . $action
        );
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function the_constructor_registers_every_admin_hook(): void
    {
        foreach (['admin_menu', 'admin_init', 'admin_footer'] as $hook) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }

        // admin_init carries two callbacks; assert both by name so a dropped
        // one is not masked by the other.
        $this->assertActionAdded('admin_init', [$this->admin, 'registerSettings']);
        $this->assertActionAdded('admin_init', [$this->admin, 'handleCacheFlush']);
    }

    /** @test */
    public function register_menu_adds_the_top_level_page_and_two_submenus(): void
    {
        $this->admin->registerMenu();

        $slugs = array_column(WpState::$menus, 'slug');

        $this->assertSame(['concordance', 'concordance', 'concordance-docs'], $slugs);
        $this->assertSame('menu', WpState::$menus[0]['type']);
        $this->assertSame('submenu', WpState::$menus[1]['type']);

        foreach (WpState::$menus as $menu) {
            $this->assertSame('manage_options', $menu['cap'], $menu['slug'] . ' should require manage_options');
        }
    }

    /** @test */
    public function register_settings_registers_every_option_in_one_group(): void
    {
        $this->admin->registerSettings();

        $this->assertSame([
            ConcordanceConfiguration::OPTION_API_KEY,
            ConcordanceConfiguration::OPTION_CACHE_TTL,
            ConcordanceConfiguration::OPTION_API_BASE_URL,
            ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT,
            ConcordanceConfiguration::OPTION_INTERGROUP_ID,
            ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS,
        ], array_keys($this->settings));

        foreach ($this->settings as $name => $spec) {
            $this->assertSame('concordance_options', $spec['group'], $name . ' is in the wrong group');
            $this->assertArrayHasKey('sanitize_callback', $spec['args'], $name . ' has no sanitize callback');
        }
    }

    /**
     * The API key must never be written to wp_options in the clear, so its
     * sanitize callback is the encryption step rather than a formatting one.
     *
     * @test
     */
    public function the_api_key_is_encrypted_by_its_sanitize_callback(): void
    {
        $this->admin->registerSettings();

        $callback = $this->settings[ConcordanceConfiguration::OPTION_API_KEY]['args']['sanitize_callback'];

        $this->assertSame([$this->admin, 'sanitizeAndEncryptApiKey'], $callback);
    }

    /** @test */
    public function register_settings_builds_two_sections_and_six_fields(): void
    {
        $this->admin->registerSettings();

        $this->assertSame(
            ['concordance_main_section', 'concordance_dashboard_section'],
            array_column($this->sections, 'id')
        );

        $this->assertCount(6, $this->fields);

        foreach ($this->fields as $field) {
            $this->assertSame('concordance', $field['page'], $field['id'] . ' is on the wrong page');
            $this->assertIsCallable($field['callback'], $field['id'] . ' has no render callback');
        }

        // The two dashboard-display settings belong to the second section.
        $bySection = array_column($this->fields, 'section', 'id');
        $this->assertSame(
            'concordance_dashboard_section',
            $bySection[ConcordanceConfiguration::OPTION_INTERGROUP_ID]
        );
        $this->assertSame(
            'concordance_dashboard_section',
            $bySection[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS]
        );
    }

    // ── sanitize callbacks ────────────────────────────────────────────

    /** @test */
    public function an_empty_api_key_is_stored_as_an_empty_string(): void
    {
        $this->assertSame('', $this->admin->sanitizeAndEncryptApiKey(''));
        $this->assertSame('', $this->admin->sanitizeAndEncryptApiKey(null));
    }

    /** @test */
    public function a_new_api_key_is_encrypted_before_storage(): void
    {
        $encryption = new Encryption();

        $stored = $this->admin->sanitizeAndEncryptApiKey('plain-text-value');

        $this->assertNotSame('plain-text-value', $stored, 'the key must not be stored in the clear');
        $this->assertTrue($encryption->isEncrypted($stored));
        $this->assertSame('plain-text-value', $encryption->decrypt($stored));
    }

    /**
     * The settings form round-trips the stored value, so resubmitting an
     * untouched field hands the callback something already encrypted. Encrypting
     * it a second time would make the key undecryptable.
     *
     * @test
     */
    public function an_already_encrypted_api_key_is_not_encrypted_twice(): void
    {
        $encryption = new Encryption();
        $once       = $encryption->encrypt('plain-text-value');

        $this->assertSame($once, $this->admin->sanitizeAndEncryptApiKey($once));
    }

    /**
     * @test
     * @dataProvider dashboardFieldSubmissions
     * @param mixed         $input
     * @param array<string> $expected
     */
    public function the_dashboard_fields_setting_is_filtered_to_the_whitelist(mixed $input, array $expected): void
    {
        $this->assertSame($expected, $this->admin->sanitizeDashboardFields($input));
    }

    /** @return array<string, array{0: mixed, 1: array<string>}> */
    public static function dashboardFieldSubmissions(): array
    {
        return [
            'not an array'              => ['day', []],
            'nothing ticked'            => [[], []],
            'a single field'            => [['town'], ['town']],
            'unknown keys are dropped'  => [['town', 'nonsense', 'DROP TABLE'], ['town']],
            'reordered to whitelist'    => [['postcode', 'day'], ['day', 'postcode']],
            'duplicates collapse'       => [['day', 'day'], ['day']],
            // The hidden empty value that makes an all-unchecked submission
            // reach the callback at all is not a whitelist key.
            'the hidden empty value'    => [[''], []],
        ];
    }

    // ── field rendering ───────────────────────────────────────────────

    /** @test */
    public function the_api_key_field_shows_the_decrypted_value_in_a_password_input(): void
    {
        $encryption = new Encryption();
        WpState::$options[ConcordanceConfiguration::OPTION_API_KEY] = $encryption->encrypt('round-trip-me');

        $html = $this->render([$this->admin, 'renderApiKeyField']);

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('value="round-trip-me"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
    }

    /**
     * Encryption falls back to obfuscation without OpenSSL, and the field says
     * so rather than implying the key is encrypted at rest. OpenSSL is loaded
     * in this environment, so only the reassuring branch is assertable — the
     * warning's absence is the assertion.
     *
     * @test
     */
    public function the_api_key_field_warns_only_when_openssl_is_missing(): void
    {
        $html = $this->render([$this->admin, 'renderApiKeyField']);

        if (extension_loaded('openssl')) {
            $this->assertStringNotContainsString('OpenSSL PHP extension is not available', $html);
        } else {
            $this->assertStringContainsString('OpenSSL PHP extension is not available', $html);
        }
    }

    /** @test */
    public function the_numeric_fields_fall_back_to_their_documented_defaults(): void
    {
        $ttl     = $this->render([$this->admin, 'renderCacheTtlField']);
        $timeout = $this->render([$this->admin, 'renderRequestTimeoutField']);

        $this->assertStringContainsString(
            'value="' . ConcordanceConfiguration::DEFAULT_CACHE_TTL . '"',
            $ttl
        );
        $this->assertStringContainsString('min="0"', $ttl);
        $this->assertStringContainsString(
            'value="' . ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT . '"',
            $timeout
        );
        $this->assertStringContainsString('min="1"', $timeout);
    }

    /** @test */
    public function the_numeric_fields_show_the_saved_values(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_CACHE_TTL]        = 120;
        WpState::$options[ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT]  = 5;

        $this->assertStringContainsString('value="120"', $this->render([$this->admin, 'renderCacheTtlField']));
        $this->assertStringContainsString('value="5"', $this->render([$this->admin, 'renderRequestTimeoutField']));
    }

    /** @test */
    public function the_base_url_field_defaults_to_the_aagbdb_api(): void
    {
        $html = $this->render([$this->admin, 'renderApiBaseUrlField']);

        $this->assertStringContainsString('type="url"', $html);
        $this->assertStringContainsString(
            'value="' . ConcordanceConfiguration::DEFAULT_API_BASE_URL . '"',
            $html
        );
    }

    /** @test */
    public function the_base_url_field_shows_the_saved_value(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_API_BASE_URL] = 'https://staging.example.test/api';

        $this->assertStringContainsString(
            'value="https://staging.example.test/api"',
            $this->render([$this->admin, 'renderApiBaseUrlField'])
        );
    }

    /** @test */
    public function the_dashboard_fields_grid_ticks_the_saved_selection(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['town'];

        $html = $this->render([$this->admin, 'renderDashboardFieldsField']);

        // One checkbox per whitelist entry, plus the hidden empty value.
        $this->assertSame(
            count(ConcordanceConfiguration::DASHBOARD_FIELDS),
            substr_count($html, 'id="concordance-field-')
        );
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="town" checked', $html);
        $this->assertStringNotContainsString('value="day" checked', $html);

        // The helper buttons and the defaults they restore.
        foreach (['all', 'none', 'defaults'] as $action) {
            $this->assertStringContainsString('data-concordance-fields-action="' . $action . '"', $html);
        }
    }

    /**
     * A corrupted option (say, a string where a list belongs) must not blank
     * the grid — the defaults stand in.
     *
     * @test
     */
    public function a_non_array_dashboard_fields_option_falls_back_to_the_defaults(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = 'corrupted';

        $html = $this->render([$this->admin, 'renderDashboardFieldsField']);

        foreach (ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS as $key) {
            $this->assertStringContainsString('value="' . $key . '" checked', $html);
        }
    }

    // ── the intergroup dropdown (built from cached API data) ──────────

    /** @test */
    public function the_intergroup_dropdown_lists_the_intergroups_the_api_returned(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertStringContainsString('>All intergroups</option>', $html);
        $this->assertStringContainsString('<option value="7">Bristol</option>', $html);
        $this->assertStringContainsString('<option value="9">Cornwall</option>', $html);
        $this->assertStringContainsString('will appear in the dashboard widget', $html);
    }

    /**
     * Sorted alphabetically by name rather than by id, because the id order
     * the API happens to return is meaningless to the person picking one.
     *
     * @test
     */
    public function the_intergroup_dropdown_is_sorted_by_name(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertLessThan(
            strpos($html, '>Cornwall<'),
            strpos($html, '>Bristol<'),
            'Bristol (id 7) should sort before Cornwall (id 9) by name, not by id'
        );
    }

    /** @test */
    public function the_saved_intergroup_is_preselected(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 9;
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertStringContainsString('<option value="9" selected>Cornwall</option>', $html);
        $this->assertStringNotContainsString('<option value="0" selected>', $html);
    }

    /** @test */
    public function the_all_sentinel_is_selected_by_default(): void
    {
        $this->cache->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertStringContainsString(
            '<option value="' . ConcordanceConfiguration::INTERGROUP_ID_ALL . '" selected>',
            $html
        );
    }

    /**
     * An intergroup with no usable name still needs a label, or it renders as
     * an empty, unpickable row.
     *
     * @test
     */
    public function an_unnamed_intergroup_falls_back_to_its_id(): void
    {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 4, 'intergroupName' => ''],
        ]);

        $this->assertStringContainsString(
            '<option value="4">Intergroup #4</option>',
            $this->render([$this->admin, 'renderIntergroupIdField'])
        );
    }

    /** @test */
    public function intergroups_are_deduplicated_and_unidentified_ones_skipped(): void
    {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 7, 'intergroupName' => 'BRISTOL'],
            ['id' => 2, 'groupName' => 'B', 'intergroupId' => 7, 'intergroupName' => 'BRISTOL'],
            ['id' => 3, 'groupName' => 'C', 'intergroupId' => 0, 'intergroupName' => 'Unassigned'],
        ]);

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertSame(1, substr_count($html, '>Bristol</option>'), 'the duplicate should collapse');
        $this->assertStringNotContainsString('Unassigned', $html, 'intergroup id 0 is the "all" sentinel');
    }

    /**
     * @test
     * @dataProvider emptyIntergroupSources
     */
    public function an_empty_choice_list_still_renders_a_usable_dropdown(
        callable $configure,
        bool $withCache
    ): void {
        $configure($this);

        $admin = $withCache
            ? new SettingsAdmin($this->client, new Encryption(), $this->cache)
            : new SettingsAdmin($this->client, new Encryption(), null);

        $html = $this->render([$admin, 'renderIntergroupIdField']);

        $this->assertStringContainsString('>All intergroups</option>', $html);
        $this->assertStringContainsString('No intergroup data is available yet', $html);
    }

    /** @return array<string, array{0: callable, 1: bool}> */
    public static function emptyIntergroupSources(): array
    {
        return [
            'no cache service' => [
                static function (self $test): void {
                },
                false,
            ],
            'the api errored' => [
                static function (self $test): void {
                    $test->cacheReturns(new WP_Error('http_error', 'unreachable'));
                },
                true,
            ],
            'the api returned nothing' => [
                static function (self $test): void {
                    $test->cacheReturns([]);
                },
                true,
            ],
        ];
    }

    /**
     * A saved intergroup that the (empty) cache cannot name must stay visible,
     * or saving the form would silently reset the filter to "All".
     *
     * @test
     */
    public function a_saved_intergroup_survives_an_empty_choice_list(): void
    {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 42;
        $this->cache->method('getGroups')->willReturn([]);

        $html = $this->render([$this->admin, 'renderIntergroupIdField']);

        $this->assertStringContainsString('<option value="42" selected>', $html);
        $this->assertStringContainsString('Intergroup #42 (currently saved)', $html);
    }

    // ── the full settings page ────────────────────────────────────────

    /** @test */
    public function the_settings_page_renders_nothing_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->assertSame('', $this->render([$this->admin, 'renderSettingsPage']));
    }

    /** @test */
    public function the_settings_page_renders_all_four_of_its_sections(): void
    {
        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('<form action="options.php" method="post">', $html);
        $this->assertStringContainsString('Cache Maintenance', $html);
        $this->assertStringContainsString('Connection Test', $html);
        $this->assertStringContainsString('Usage', $html);
        $this->assertStringContainsString('/wp-json/' . ConcordanceConfiguration::REST_NAMESPACE . '/groups', $html);
        $this->assertStringContainsString('wp concordance flush-cache', $html);
    }

    /** @test */
    public function the_flush_cache_link_carries_its_own_nonce(): void
    {
        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('concordance_flush_cache=1', $html);
        $this->assertStringContainsString('_wpnonce=nonce-concordance_flush_cache_nonce', $html);
        $this->assertStringContainsString('concordance_test=1', $html);
        $this->assertStringContainsString('_wpnonce=nonce-concordance_test_nonce', $html);
    }

    /**
     * @test
     * @dataProvider flushResultFlags
     */
    public function the_cache_flush_result_is_reported_back_on_the_page(
        string $flag,
        string $expected,
        string $noticeClass
    ): void {
        $_GET['concordance_flushed'] = $flag;

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString($noticeClass, $html);
        $this->assertStringContainsString($expected, $html);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function flushResultFlags(): array
    {
        return [
            'rejected nonce'    => ['invalid', 'invalid security token', 'notice-error'],
            'no cache service'  => ['unavailable', 'Cache service is unavailable', 'notice-error'],
            'flush threw'       => ['error', 'Cache flush failed', 'notice-error'],
            'nothing cached'    => ['0', 'Cache flushed: 0 cached entries cleared.', 'notice-success'],
            'several cleared'   => ['12', 'Cache flushed: 12 cached entries cleared.', 'notice-success'],
        ];
    }

    /** @test */
    public function an_unrecognised_flush_flag_renders_no_notice(): void
    {
        $_GET['concordance_flushed'] = 'not-a-flag';

        $this->assertStringNotContainsString(
            'is-dismissible',
            $this->render([$this->admin, 'renderSettingsPage'])
        );
    }

    // ── the connection test ───────────────────────────────────────────

    /** @test */
    public function the_api_is_not_called_until_the_connection_test_is_requested(): void
    {
        $this->client->expects($this->never())->method('getGroups');

        $this->render([$this->admin, 'renderSettingsPage']);
    }

    /** @test */
    public function the_connection_test_is_ignored_without_a_valid_nonce(): void
    {
        $_GET['concordance_test'] = '1';
        $_GET['_wpnonce']         = 'forged';
        $this->client->expects($this->never())->method('getGroups');

        $this->render([$this->admin, 'renderSettingsPage']);
    }

    /** @test */
    public function a_successful_connection_test_reports_the_group_count(): void
    {
        $this->requestConnectionTest();
        $this->client->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Received 3 group(s) from the API', $html);
    }

    /**
     * The first record is echoed to the browser console so the payload shape
     * can be inspected when choosing Visible Fields.
     *
     * @test
     */
    public function a_successful_connection_test_logs_the_first_raw_record(): void
    {
        $this->requestConnectionTest();
        $this->client->method('getGroups')->willReturn($this->groupsResponse());

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('<script>console.log(', $html);
        // The *first* record, pretty-printed — not the whole collection.
        $this->assertStringContainsString('"groupName": "Monday Nooners"', $html);
        $this->assertStringNotContainsString('Tuesday Steps', $html);
        $this->assertStringContainsString('open DevTools', $html);
    }

    /** @test */
    public function an_empty_successful_response_logs_nothing_to_the_console(): void
    {
        $this->requestConnectionTest();
        $this->client->method('getGroups')->willReturn([]);

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('Received 0 group(s) from the API', $html);
        $this->assertStringNotContainsString('console.log', $html);
    }

    /** @test */
    public function a_failed_connection_test_shows_the_api_error_message(): void
    {
        $this->requestConnectionTest();
        $this->client->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('Connection refused', $html);
    }

    /**
     * A thrown exception must surface as a notice rather than a white screen
     * over the whole settings page.
     *
     * @test
     */
    public function a_thrown_exception_during_the_connection_test_is_caught(): void
    {
        $this->requestConnectionTest();
        $this->client->method('getGroups')->willThrowException(new RuntimeException('client exploded'));

        $html = $this->render([$this->admin, 'renderSettingsPage']);

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('client exploded', $html);
        // The rest of the page still renders.
        $this->assertStringContainsString('Test API Connection', $html);
    }

    /**
     * @test
     * @dataProvider apiEnvelopes
     * @param array<string, mixed>|null $expected
     */
    public function the_first_raw_record_is_unwrapped_from_any_envelope(
        mixed $response,
        ?array $expected
    ): void {
        $method = new ReflectionMethod(SettingsAdmin::class, 'extractFirstRawResult');

        $this->assertSame($expected, $method->invoke($this->admin, $response));
    }

    /** @return array<string, array{0: mixed, 1: array<string, mixed>|null}> */
    public static function apiEnvelopes(): array
    {
        $record = ['id' => 1, 'groupName' => 'First'];

        return [
            'not an array'        => ['nope', null],
            'empty'               => [[], null],
            'a bare list'         => [[$record, ['id' => 2]], $record],
            'a results envelope'  => [['results' => [$record]], $record],
            'a data envelope'     => [['data' => [$record]], $record],
            'a single record'     => [$record, $record],
            'an empty envelope'   => [['results' => []], null],
            'a list of scalars'   => [['nope'], null],
        ];
    }

    // ── cache flush (reflection: the live caller exits) ───────────────

    /**
     * @test
     * @dataProvider ignoredFlushRequests
     * @param array<string, string> $get
     */
    public function a_flush_that_should_be_ignored_produces_no_redirect(array $get, bool $userCan): void
    {
        $_GET             = $get;
        WpState::$userCan = $userCan;

        $this->assertNull($this->resolveFlush());
    }

    /** @return array<string, array{0: array<string, string>, 1: bool}> */
    public static function ignoredFlushRequests(): array
    {
        return [
            'no flush requested'      => [[], true],
            'requested without a cap' => [['concordance_flush_cache' => '1'], false],
        ];
    }

    /** @test */
    public function a_flush_with_a_forged_nonce_is_rejected(): void
    {
        $_GET = ['concordance_flush_cache' => '1', '_wpnonce' => 'forged'];
        $this->cache->expects($this->never())->method('flush');

        $this->assertStringContainsString('concordance_flushed=invalid', (string) $this->resolveFlush());
    }

    /** @test */
    public function a_flush_with_no_nonce_at_all_is_rejected(): void
    {
        $_GET = ['concordance_flush_cache' => '1'];

        $this->assertStringContainsString('concordance_flushed=invalid', (string) $this->resolveFlush());
    }

    /** @test */
    public function a_flush_without_a_cache_service_reports_it_as_unavailable(): void
    {
        $_GET  = $this->validFlushRequest();
        $admin = new SettingsAdmin($this->client, new Encryption(), null);

        $this->assertStringContainsString(
            'concordance_flushed=unavailable',
            (string) $this->resolveFlush($admin)
        );
    }

    /** @test */
    public function a_successful_flush_reports_how_many_entries_were_cleared(): void
    {
        $_GET = $this->validFlushRequest();
        $this->cache->expects($this->once())->method('flush')->willReturn(12);

        $this->assertStringContainsString('concordance_flushed=12', (string) $this->resolveFlush());
    }

    /** @test */
    public function a_flush_that_throws_reports_an_error_rather_than_dying(): void
    {
        $_GET = $this->validFlushRequest();
        $this->cache->method('flush')->willThrowException(new RuntimeException('db gone'));

        $this->assertStringContainsString('concordance_flushed=error', (string) $this->resolveFlush());
    }

    /** @test */
    public function the_flush_redirect_lands_back_on_the_settings_page(): void
    {
        $_GET = $this->validFlushRequest();
        $this->cache->method('flush')->willReturn(0);

        $target = (string) $this->resolveFlush();

        $this->assertStringContainsString('/wp-admin/admin.php', $target);
        $this->assertStringContainsString('page=concordance', $target);
    }

    /** @test */
    public function handle_cache_flush_leaves_an_unrelated_request_alone(): void
    {
        $this->admin->handleCacheFlush();

        $this->assertSame([], WpState::$redirects);
    }

    // ── documentation link ────────────────────────────────────────────

    /** @test */
    public function the_docs_page_opens_the_bundled_html_in_a_new_tab(): void
    {
        $expected = CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html';

        $html = $this->render([$this->admin, 'renderDocsRedirect']);

        $this->assertStringContainsString('window.open(', $html);
        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    /** @test */
    public function the_admin_footer_script_retargets_the_docs_menu_link(): void
    {
        $html = $this->render([$this->admin, 'addDocsNewTabScript']);

        $this->assertStringContainsString('a[href="admin.php?page=concordance-docs"]', $html);
        // The URL goes through wp_json_encode(), so it lands slash-escaped.
        $this->assertStringContainsString(
            (string) json_encode(CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html'),
            $html
        );
        $this->assertStringContainsString("setAttribute('target', '_blank')", $html);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * Seed the ApiCache double's response. Public so the data providers'
     * closures can reach it.
     *
     * @param array<string, mixed>|WP_Error $response
     */
    public function cacheReturns(array|WP_Error $response): void
    {
        $this->cache->method('getGroups')->willReturn($response);
    }

    /** Mark the current request as a nonce-verified connection test. */
    private function requestConnectionTest(): void
    {
        $_GET['concordance_test'] = '1';
        $_GET['_wpnonce']         = 'nonce-concordance_test_nonce';
    }

    /** @return array<string, string> */
    private function validFlushRequest(): array
    {
        return [
            'concordance_flush_cache' => '1',
            '_wpnonce'                => 'nonce-concordance_flush_cache_nonce',
        ];
    }

    private function resolveFlush(?SettingsAdmin $admin = null): ?string
    {
        $method = new ReflectionMethod(SettingsAdmin::class, 'resolveCacheFlushRedirect');

        /** @var string|null $target */
        $target = $method->invoke($admin ?? $this->admin);

        return $target;
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
     * A three-group API response spanning two intergroups, with the
     * alphabetically later intergroup first so sorting is observable.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupsResponse(): array
    {
        return [
            [
                'id' => 1, 'groupName' => 'Monday Nooners', 'town' => 'BRISTOL',
                'intergroupId' => 9, 'intergroupName' => 'CORNWALL',
                'day' => 'Monday', 'startTime' => '12:00', 'endTime' => '13:00',
            ],
            [
                'id' => 2, 'groupName' => 'Tuesday Steps', 'town' => 'BATH',
                'intergroupId' => 7, 'intergroupName' => 'BRISTOL',
                'day' => 'Tuesday', 'startTime' => '19:30', 'endTime' => '21:00',
            ],
            [
                'id' => 3, 'groupName' => 'Wednesday Big Book', 'town' => 'WELLS',
                'intergroupId' => 7, 'intergroupName' => 'BRISTOL',
                'day' => 'Wednesday', 'startTime' => '18:00', 'endTime' => '19:30',
            ],
        ];
    }
}
