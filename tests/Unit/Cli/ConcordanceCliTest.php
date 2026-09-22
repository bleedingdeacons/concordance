<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Cli;

use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use Concordance\Cli\ConcordanceCli;
use Concordance\Common\Encryption;
use ConcordanceCliExit;
use WP_Error;

/*
 * Tests for the `wp concordance` command, against the WP-CLI stand-ins in
 * tests/wp-stubs.php, which record what was logged and formatted.
 */

covers(\Concordance\Cli\ConcordanceCli::class);

/**
 * @return array<int, array<string, mixed>>
 */
function cliSampleGroups(): array
{
    return [
        ['id' => 1, 'groupName' => 'Alpha', 'intergroupId' => 1, 'day' => 'Monday'],
        ['id' => 2, 'groupName' => 'Beta', 'intergroupId' => 2, 'day' => 'Tuesday'],
    ];
}

beforeEach(function () {
    // The WP-CLI log and formatter are local stubs, so they reset here;
    // the TestCase's setUp() has already cleared the options in WpState.
    $GLOBALS['conc_cli_log'] = [];
    $GLOBALS['conc_cli_formatted'] = null;
    $this->client = $this->createMock(ApiClient::class);
    $this->cache = $this->createMock(ApiCache::class);
    $this->cli = new ConcordanceCli($this->client, $this->cache);
});

// ── list ─────────────────────────────────────────────────────────────
describe('list', function () {
    it('lists groups from the cache and formats them', function () {
        $this->cache->method('getGroups')->willReturn(cliSampleGroups());
        $this->cli->list_groups([], []);
        expect($GLOBALS['conc_cli_formatted'])->not->toBeNull()
            ->and($GLOBALS['conc_cli_formatted']['items'])->toHaveCount(2);
    });

    it('uses the client directly with --no-cache', function () {
        $this->client->expects($this->once())->method('getGroups')->willReturn(cliSampleGroups());
        $this->cli->list_groups([], ['no-cache' => true]);
        expect($GLOBALS['conc_cli_formatted'])->not->toBeNull();
    });

    it('errors on a WP_Error', function () {
        $this->cache->method('getGroups')->willReturn(new WP_Error('e', 'failed'));
        $this->cli->list_groups([], []);
    })->throws(ConcordanceCliExit::class);

    it('warns when there are no groups', function () {
        $this->cache->method('getGroups')->willReturn([]);
        $this->cli->list_groups([], []);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('warning');
    });

    it('filters by intergroup, sorts and limits', function () {
        $this->cache->method('getGroups')->willReturn(cliSampleGroups());
        $this->cli->list_groups([], ['intergroup' => 1, 'sort' => 'day', 'limit' => 5]);
        expect($GLOBALS['conc_cli_formatted']['items'])->toHaveCount(1);
    });

    it('warns when the intergroup filter leaves nothing', function () {
        $this->cache->method('getGroups')->willReturn(cliSampleGroups());
        $this->cli->list_groups([], ['intergroup' => 999]);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('warning');
    });
});

// ── get ──────────────────────────────────────────────────────────────
describe('get', function () {
    it('errors without an id', function () {
        $this->cli->get([], []);
    })->throws(ConcordanceCliExit::class);

    it('errors on a WP_Error', function () {
        $this->client->method('getGroup')->willReturn(new WP_Error('e', 'bad'));
        $this->cli->get(['42'], []);
    })->throws(ConcordanceCliExit::class);

    it('warns when the group is not found', function () {
        $this->client->method('getGroup')->willReturn([]);
        $this->cli->get(['42'], []);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('warning');
    });

    it('formats a single group', function () {
        $this->client->method('getGroup')->willReturn(['id' => 42, 'groupName' => 'Gamma']);
        $this->cli->get(['42'], ['format' => 'json']);
        expect($GLOBALS['conc_cli_formatted']['format'])->toBe('json');
    });
});

// ── test / flush / config / version ──────────────────────────────────
describe('test, flush, config and version', function () {
    it('reports success from test', function () {
        $this->client->method('getGroups')->willReturn(cliSampleGroups());
        $this->cli->test([], []);
        $kinds = array_column($GLOBALS['conc_cli_log'], 0);
        expect($kinds)->toContain('success');
    });

    it('errors from test on failure', function () {
        $this->client->method('getGroups')->willReturn(new WP_Error('e', 'down'));
        $this->cli->test([], []);
    })->throws(ConcordanceCliExit::class);

    it('reports success from flush_cache', function () {
        $this->cache->method('flush')->willReturn(true);
        $this->cli->flush_cache([], []);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('success');
    });

    it('warns from flush_cache when the version could not be written', function () {
        $this->cache->method('flush')->willReturn(false);
        $this->cli->flush_cache([], []);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('warning');
    });

    it('formats the settings from config', function () {
        WpState::$options['concordance_api_key'] = (new Encryption())->encrypt('abcdefghijklmnop');
        $this->cli->config([], []);
        expect($GLOBALS['conc_cli_formatted'])->not->toBeNull();
        $settings = array_column($GLOBALS['conc_cli_formatted']['items'], 'Setting');
        expect($settings)->toContain('API Key');
    });

    it('formats config with no API key', function () {
        $this->cli->config([], []);
        expect($GLOBALS['conc_cli_formatted'])->not->toBeNull();
    });

    it('logs the version', function () {
        $this->cli->version([], []);
        expect($GLOBALS['conc_cli_log'][0][0])->toBe('log');
    });
});
