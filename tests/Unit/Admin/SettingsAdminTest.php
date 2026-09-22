<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Admin;

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

/*
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
 */

covers(\Concordance\Admin\SettingsAdmin::class);

// The record extractFirstRawResult() should find, whatever wraps it.
const FIRST_RAW_RECORD = ['id' => 1, 'groupName' => 'First'];

/** Mark the current request as a nonce-verified connection test. */
function requestConnectionTest(): void
{
    $_GET['concordance_test'] = '1';
    $_GET['_wpnonce']         = 'nonce-concordance_test_nonce';
}

/** @return array<string, string> */
function validFlushRequest(): array
{
    return [
        'concordance_flush_cache' => '1',
        '_wpnonce'                => 'nonce-concordance_flush_cache_nonce',
    ];
}

function resolveFlush(SettingsAdmin $admin): ?string
{
    $method = new ReflectionMethod(SettingsAdmin::class, 'resolveCacheFlushRedirect');

    /** @var string|null $target */
    $target = $method->invoke($admin);

    return $target;
}

/**
 * A three-group API response spanning two intergroups, with the
 * alphabetically later intergroup first so sorting is observable.
 *
 * @return array<int, array<string, mixed>>
 */
function settingsGroupsResponse(): array
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

beforeEach(function () {
    $_GET = [];

    // Settings API calls captured from registerSettings().
    $this->settings = [];
    $this->sections = [];
    $this->fields   = [];

    // The Settings API and a handful of admin-page helpers are outside what
    // wp-mocks stubs, so they are defined here. The three registration
    // functions record rather than discard, which is what the registration
    // tests below assert on.
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

    $this->client = $this->createMock(ApiClient::class);
    $this->cache  = $this->createMock(ApiCache::class);
    $this->admin  = new SettingsAdmin($this->client, new Encryption(), $this->cache);
});

afterEach(function () {
    $_GET = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers every admin hook from the constructor', function () {
        foreach (['admin_menu', 'admin_init', 'admin_footer'] as $hook) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }

        // admin_init carries two callbacks; assert both by name so a dropped
        // one is not masked by the other.
        $this->assertActionAdded('admin_init', [$this->admin, 'registerSettings']);
        $this->assertActionAdded('admin_init', [$this->admin, 'handleCacheFlush']);
    });

    it('adds the top-level page and two submenus', function () {
        $this->admin->registerMenu();

        $slugs = array_column(WpState::$menus, 'slug');

        expect($slugs)->toBe(['concordance', 'concordance', 'concordance-docs'])
            ->and(WpState::$menus[0]['type'])->toBe('menu')
            ->and(WpState::$menus[1]['type'])->toBe('submenu');

        foreach (WpState::$menus as $menu) {
            expect($menu['cap'])->toBe('manage_options', $menu['slug'] . ' should require manage_options');
        }
    });

    it('registers every option in one group', function () {
        $this->admin->registerSettings();

        expect(array_keys($this->settings))->toBe([
            ConcordanceConfiguration::OPTION_API_KEY,
            ConcordanceConfiguration::OPTION_CACHE_TTL,
            ConcordanceConfiguration::OPTION_API_BASE_URL,
            ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT,
            ConcordanceConfiguration::OPTION_INTERGROUP_ID,
            ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS,
        ]);

        foreach ($this->settings as $name => $spec) {
            expect($spec['group'])->toBe('concordance_options', $name . ' is in the wrong group')
                ->and($spec['args'])->toHaveKey('sanitize_callback', message: $name . ' has no sanitize callback');
        }
    });

    // The API key must never be written to wp_options in the clear, so its
    // sanitize callback is the encryption step rather than a formatting one.
    it('encrypts the API key through its sanitize callback', function () {
        $this->admin->registerSettings();

        $callback = $this->settings[ConcordanceConfiguration::OPTION_API_KEY]['args']['sanitize_callback'];

        expect($callback)->toBe([$this->admin, 'sanitizeAndEncryptApiKey']);
    });

    it('builds two sections and six fields', function () {
        $this->admin->registerSettings();

        expect(array_column($this->sections, 'id'))
            ->toBe(['concordance_main_section', 'concordance_dashboard_section'])
            ->and($this->fields)->toHaveCount(6);

        foreach ($this->fields as $field) {
            expect($field['page'])->toBe('concordance', $field['id'] . ' is on the wrong page')
                ->and($field['callback'])->toBeCallable($field['id'] . ' has no render callback');
        }

        // The two dashboard-display settings belong to the second section.
        $bySection = array_column($this->fields, 'section', 'id');
        expect($bySection[ConcordanceConfiguration::OPTION_INTERGROUP_ID])->toBe('concordance_dashboard_section')
            ->and($bySection[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS])->toBe('concordance_dashboard_section');
    });
});

// ── sanitize callbacks ────────────────────────────────────────────
describe('sanitize callbacks', function () {
    it('stores an empty API key as an empty string', function () {
        expect($this->admin->sanitizeAndEncryptApiKey(''))->toBe('')
            ->and($this->admin->sanitizeAndEncryptApiKey(null))->toBe('');
    });

    it('encrypts a new API key before storage', function () {
        $encryption = new Encryption();

        $stored = $this->admin->sanitizeAndEncryptApiKey('plain-text-value');

        expect($stored)->not->toBe('plain-text-value', 'the key must not be stored in the clear')
            ->and($encryption->isEncrypted($stored))->toBeTrue()
            ->and($encryption->decrypt($stored))->toBe('plain-text-value');
    });

    // The settings form round-trips the stored value, so resubmitting an
    // untouched field hands the callback something already encrypted. Encrypting
    // it a second time would make the key undecryptable.
    it('does not encrypt an already-encrypted API key twice', function () {
        $encryption = new Encryption();
        $once       = $encryption->encrypt('plain-text-value');

        expect($this->admin->sanitizeAndEncryptApiKey($once))->toBe($once);
    });

    it('filters the dashboard fields setting to the whitelist', function (mixed $input, array $expected) {
        expect($this->admin->sanitizeDashboardFields($input))->toBe($expected);
    })->with([
        'not an array'              => ['day', []],
        'nothing ticked'            => [[], []],
        'a single field'            => [['town'], ['town']],
        'unknown keys are dropped'  => [['town', 'nonsense', 'DROP TABLE'], ['town']],
        'reordered to whitelist'    => [['postcode', 'day'], ['day', 'postcode']],
        'duplicates collapse'       => [['day', 'day'], ['day']],
        // The hidden empty value that makes an all-unchecked submission
        // reach the callback at all is not a whitelist key.
        'the hidden empty value'    => [[''], []],
    ]);
});

// ── field rendering ───────────────────────────────────────────────
describe('field rendering', function () {
    it('shows the decrypted API key in a password input', function () {
        $encryption = new Encryption();
        WpState::$options[ConcordanceConfiguration::OPTION_API_KEY] = $encryption->encrypt('round-trip-me');

        $html = captureOutput([$this->admin, 'renderApiKeyField']);

        expect($html)->toContain('type="password"')
            ->toContain('value="round-trip-me"')
            ->toContain('autocomplete="off"');
    });

    // Encryption falls back to obfuscation without OpenSSL, and the field says
    // so rather than implying the key is encrypted at rest. OpenSSL is loaded
    // in this environment, so only the reassuring branch is assertable — the
    // warning's absence is the assertion.
    it('warns on the API key field only when OpenSSL is missing', function () {
        $html = captureOutput([$this->admin, 'renderApiKeyField']);

        if (extension_loaded('openssl')) {
            expect($html)->not->toContain('OpenSSL PHP extension is not available');
        } else {
            expect($html)->toContain('OpenSSL PHP extension is not available');
        }
    });

    it('falls back to the documented defaults in the numeric fields', function () {
        $ttl     = captureOutput([$this->admin, 'renderCacheTtlField']);
        $timeout = captureOutput([$this->admin, 'renderRequestTimeoutField']);

        expect($ttl)->toContain('value="' . ConcordanceConfiguration::DEFAULT_CACHE_TTL . '"')
            ->toContain('min="0"')
            ->and($timeout)->toContain('value="' . ConcordanceConfiguration::DEFAULT_REQUEST_TIMEOUT . '"')
            ->toContain('min="1"');
    });

    it('shows the saved values in the numeric fields', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_CACHE_TTL]        = 120;
        WpState::$options[ConcordanceConfiguration::OPTION_REQUEST_TIMEOUT]  = 5;

        expect(captureOutput([$this->admin, 'renderCacheTtlField']))->toContain('value="120"')
            ->and(captureOutput([$this->admin, 'renderRequestTimeoutField']))->toContain('value="5"');
    });

    it('defaults the base URL field to the AAGBDB API', function () {
        $html = captureOutput([$this->admin, 'renderApiBaseUrlField']);

        expect($html)->toContain('type="url"')
            ->toContain('value="' . ConcordanceConfiguration::DEFAULT_API_BASE_URL . '"');
    });

    it('shows the saved value in the base URL field', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_API_BASE_URL] = 'https://staging.example.test/api';

        expect(captureOutput([$this->admin, 'renderApiBaseUrlField']))
            ->toContain('value="https://staging.example.test/api"');
    });

    it('ticks the saved selection in the dashboard fields grid', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = ['town'];

        $html = captureOutput([$this->admin, 'renderDashboardFieldsField']);

        // One checkbox per whitelist entry, plus the hidden empty value.
        expect(substr_count($html, 'id="concordance-field-'))->toBe(count(ConcordanceConfiguration::DASHBOARD_FIELDS))
            ->and($html)->toContain('type="hidden"')
            ->toContain('value="town" checked')
            ->not->toContain('value="day" checked');

        // The helper buttons and the defaults they restore.
        foreach (['all', 'none', 'defaults'] as $action) {
            expect($html)->toContain('data-concordance-fields-action="' . $action . '"');
        }
    });

    // A corrupted option (say, a string where a list belongs) must not blank
    // the grid — the defaults stand in.
    it('falls back to the defaults for a non-array dashboard fields option', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_DASHBOARD_FIELDS] = 'corrupted';

        $html = captureOutput([$this->admin, 'renderDashboardFieldsField']);

        foreach (ConcordanceConfiguration::DEFAULT_DASHBOARD_FIELDS as $key) {
            expect($html)->toContain('value="' . $key . '" checked');
        }
    });
});

// ── the intergroup dropdown (built from cached API data) ──────────
describe('the intergroup dropdown', function () {
    it('lists the intergroups the API returned', function () {
        $this->cache->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect($html)->toContain('>All intergroups</option>')
            ->toContain('<option value="7">Bristol</option>')
            ->toContain('<option value="9">Cornwall</option>')
            ->toContain('will appear in the dashboard widget');
    });

    // Sorted alphabetically by name rather than by id, because the id order
    // the API happens to return is meaningless to the person picking one.
    it('is sorted by name', function () {
        $this->cache->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect(strpos($html, '>Bristol<'))->toBeLessThan(
            strpos($html, '>Cornwall<'),
            'Bristol (id 7) should sort before Cornwall (id 9) by name, not by id'
        );
    });

    it('preselects the saved intergroup', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 9;
        $this->cache->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect($html)->toContain('<option value="9" selected>Cornwall</option>')
            ->not->toContain('<option value="0" selected>');
    });

    it('selects the all sentinel by default', function () {
        $this->cache->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect($html)->toContain('<option value="' . ConcordanceConfiguration::INTERGROUP_ID_ALL . '" selected>');
    });

    // An intergroup with no usable name still needs a label, or it renders as
    // an empty, unpickable row.
    it('falls back to the id for an unnamed intergroup', function () {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 4, 'intergroupName' => ''],
        ]);

        expect(captureOutput([$this->admin, 'renderIntergroupIdField']))
            ->toContain('<option value="4">Intergroup #4</option>');
    });

    it('deduplicates intergroups and skips unidentified ones', function () {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'A', 'intergroupId' => 7, 'intergroupName' => 'BRISTOL'],
            ['id' => 2, 'groupName' => 'B', 'intergroupId' => 7, 'intergroupName' => 'BRISTOL'],
            ['id' => 3, 'groupName' => 'C', 'intergroupId' => 0, 'intergroupName' => 'Unassigned'],
        ]);

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect(substr_count($html, '>Bristol</option>'))->toBe(1, 'the duplicate should collapse')
            ->and(str_contains($html, 'Unassigned'))->toBeFalse('intergroup id 0 is the "all" sentinel');
    });

    // The response seeds the ApiCache double; null leaves it unconfigured,
    // which is what the no-cache-service case wants.
    it('still renders a usable dropdown from an empty choice list', function (array|WP_Error|null $response, bool $withCache) {
        if ($response !== null) {
            $this->cache->method('getGroups')->willReturn($response);
        }

        $admin = $withCache
            ? new SettingsAdmin($this->client, new Encryption(), $this->cache)
            : new SettingsAdmin($this->client, new Encryption(), null);

        $html = captureOutput([$admin, 'renderIntergroupIdField']);

        expect($html)->toContain('>All intergroups</option>')
            ->toContain('No intergroup data is available yet');
    })->with([
        'no cache service'         => [null, false],
        'the api errored'          => [new WP_Error('http_error', 'unreachable'), true],
        'the api returned nothing' => [[], true],
    ]);

    // A saved intergroup that the (empty) cache cannot name must stay visible,
    // or saving the form would silently reset the filter to "All".
    it('keeps a saved intergroup through an empty choice list', function () {
        WpState::$options[ConcordanceConfiguration::OPTION_INTERGROUP_ID] = 42;
        $this->cache->method('getGroups')->willReturn([]);

        $html = captureOutput([$this->admin, 'renderIntergroupIdField']);

        expect($html)->toContain('<option value="42" selected>')
            ->toContain('Intergroup #42 (currently saved)');
    });
});

// ── the full settings page ────────────────────────────────────────
describe('the settings page', function () {
    it('renders nothing without the capability', function () {
        WpState::$userCan = false;

        expect(captureOutput([$this->admin, 'renderSettingsPage']))->toBe('');
    });

    it('renders all four of its sections', function () {
        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('<form action="options.php" method="post">')
            ->toContain('Cache Maintenance')
            ->toContain('Connection Test')
            ->toContain('Usage')
            ->toContain('/wp-json/' . ConcordanceConfiguration::REST_NAMESPACE . '/groups')
            ->toContain('wp concordance flush-cache');
    });

    it('gives the flush cache link its own nonce', function () {
        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('concordance_flush_cache=1')
            ->toContain('_wpnonce=nonce-concordance_flush_cache_nonce')
            ->toContain('concordance_test=1')
            ->toContain('_wpnonce=nonce-concordance_test_nonce');
    });

    it('reports the cache flush result back on the page', function (string $flag, string $expected, string $noticeClass) {
        $_GET['concordance_flushed'] = $flag;

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain($noticeClass)
            ->toContain($expected);
    })->with([
        'rejected nonce'    => ['invalid', 'invalid security token', 'notice-error'],
        'no cache service'  => ['unavailable', 'Cache service is unavailable', 'notice-error'],
        'flush threw'       => ['error', 'Cache flush failed', 'notice-error'],
        'flushed'           => ['cleared', 'Cache flushed.', 'notice-success'],
    ]);

    // A count is no longer a flag: flushing advances a generation, so there is
    // nothing to count. A link from before that change carries a digit, and
    // rendering nothing beats reporting a number that was always zero on a
    // site with an object cache.
    it('renders no notice for a stale numeric flush flag', function () {
        $_GET['concordance_flushed'] = '12';

        expect(captureOutput([$this->admin, 'renderSettingsPage']))->not->toContain('is-dismissible');
    });

    it('renders no notice for an unrecognised flush flag', function () {
        $_GET['concordance_flushed'] = 'not-a-flag';

        expect(captureOutput([$this->admin, 'renderSettingsPage']))->not->toContain('is-dismissible');
    });
});

// ── the connection test ───────────────────────────────────────────
describe('the connection test', function () {
    it('does not call the API until the connection test is requested', function () {
        $this->client->expects($this->never())->method('getGroups');

        captureOutput([$this->admin, 'renderSettingsPage']);
    });

    it('is ignored without a valid nonce', function () {
        $_GET['concordance_test'] = '1';
        $_GET['_wpnonce']         = 'forged';
        $this->client->expects($this->never())->method('getGroups');

        captureOutput([$this->admin, 'renderSettingsPage']);
    });

    it('reports the group count on success', function () {
        requestConnectionTest();
        $this->client->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('notice-success')
            ->toContain('Received 3 group(s) from the API');
    });

    // The first record is echoed to the browser console so the payload shape
    // can be inspected when choosing Visible Fields.
    it('logs the first raw record on success', function () {
        requestConnectionTest();
        $this->client->method('getGroups')->willReturn(settingsGroupsResponse());

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('<script>console.log(')
            // The *first* record, pretty-printed — not the whole collection.
            ->toContain('"groupName": "Monday Nooners"')
            ->not->toContain('Tuesday Steps')
            ->toContain('open DevTools');
    });

    it('logs nothing to the console for an empty successful response', function () {
        requestConnectionTest();
        $this->client->method('getGroups')->willReturn([]);

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('Received 0 group(s) from the API')
            ->not->toContain('console.log');
    });

    it('shows the API error message on failure', function () {
        requestConnectionTest();
        $this->client->method('getGroups')->willReturn(new WP_Error('http_error', 'Connection refused'));

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('notice-error')
            ->toContain('Connection refused');
    });

    // A thrown exception must surface as a notice rather than a white screen
    // over the whole settings page.
    it('catches a thrown exception', function () {
        requestConnectionTest();
        $this->client->method('getGroups')->willThrowException(new RuntimeException('client exploded'));

        $html = captureOutput([$this->admin, 'renderSettingsPage']);

        expect($html)->toContain('notice-error')
            ->toContain('client exploded')
            // The rest of the page still renders.
            ->toContain('Test API Connection');
    });

    it('unwraps the first raw record from any envelope', function (mixed $response, ?array $expected) {
        $method = new ReflectionMethod(SettingsAdmin::class, 'extractFirstRawResult');

        expect($method->invoke($this->admin, $response))->toBe($expected);
    })->with([
        'not an array'        => ['nope', null],
        'empty'               => [[], null],
        'a bare list'         => [[FIRST_RAW_RECORD, ['id' => 2]], FIRST_RAW_RECORD],
        'a results envelope'  => [['results' => [FIRST_RAW_RECORD]], FIRST_RAW_RECORD],
        'a data envelope'     => [['data' => [FIRST_RAW_RECORD]], FIRST_RAW_RECORD],
        'a single record'     => [FIRST_RAW_RECORD, FIRST_RAW_RECORD],
        'an empty envelope'   => [['results' => []], null],
        'a list of scalars'   => [['nope'], null],
    ]);
});

// ── cache flush (reflection: the live caller exits) ───────────────
describe('cache flush', function () {
    it('produces no redirect for a flush that should be ignored', function (array $get, bool $userCan) {
        $_GET             = $get;
        WpState::$userCan = $userCan;

        expect(resolveFlush($this->admin))->toBeNull();
    })->with([
        'no flush requested'      => [[], true],
        'requested without a cap' => [['concordance_flush_cache' => '1'], false],
    ]);

    it('rejects a flush with a forged nonce', function () {
        $_GET = ['concordance_flush_cache' => '1', '_wpnonce' => 'forged'];
        $this->cache->expects($this->never())->method('flush');

        expect((string) resolveFlush($this->admin))->toContain('concordance_flushed=invalid');
    });

    it('rejects a flush with no nonce at all', function () {
        $_GET = ['concordance_flush_cache' => '1'];

        expect((string) resolveFlush($this->admin))->toContain('concordance_flushed=invalid');
    });

    it('reports a flush without a cache service as unavailable', function () {
        $_GET  = validFlushRequest();
        $admin = new SettingsAdmin($this->client, new Encryption(), null);

        expect((string) resolveFlush($admin))->toContain('concordance_flushed=unavailable');
    });

    it('says so after a successful flush', function () {
        $_GET = validFlushRequest();
        $this->cache->expects($this->once())->method('flush')->willReturn(true);

        expect((string) resolveFlush($this->admin))->toContain('concordance_flushed=cleared');
    });

    it('reports an error for a flush that could not write the version', function () {
        $_GET = validFlushRequest();
        $this->cache->expects($this->once())->method('flush')->willReturn(false);

        expect((string) resolveFlush($this->admin))->toContain('concordance_flushed=error');
    });

    it('reports an error rather than dying for a flush that throws', function () {
        $_GET = validFlushRequest();
        $this->cache->method('flush')->willThrowException(new RuntimeException('db gone'));

        expect((string) resolveFlush($this->admin))->toContain('concordance_flushed=error');
    });

    it('lands the flush redirect back on the settings page', function () {
        $_GET = validFlushRequest();
        $this->cache->method('flush')->willReturn(true);

        $target = (string) resolveFlush($this->admin);

        expect($target)->toContain('/wp-admin/admin.php')
            ->toContain('page=concordance');
    });

    it('leaves an unrelated request alone in handleCacheFlush', function () {
        $this->admin->handleCacheFlush();

        expect(WpState::$redirects)->toBe([]);
    });
});

// ── documentation link ────────────────────────────────────────────
describe('documentation link', function () {
    it('opens the bundled HTML in a new tab from the docs page', function () {
        $expected = CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html';

        $html = captureOutput([$this->admin, 'renderDocsRedirect']);

        expect($html)->toContain('window.open(')
            ->toContain($expected)
            ->toContain('target="_blank"');
    });

    it('retargets the docs menu link from the admin footer script', function () {
        $html = captureOutput([$this->admin, 'addDocsNewTabScript']);

        expect($html)->toContain('a[href="admin.php?page=concordance-docs"]')
            // The URL goes through wp_json_encode(), so it lands slash-escaped.
            ->toContain((string) json_encode(CONCORDANCE_PLUGIN_URL . 'assets/docs/concordance.html'))
            ->toContain("setAttribute('target', '_blank')");
    });
});
