<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Cli;

use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use Concordance\Cli\ConcordanceCli;
use ConcordanceCliExit;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers \Concordance\Cli\ConcordanceCli
 */
class ConcordanceCliTest extends TestCase
{
    /** @var ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;
    /** @var ApiCache&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;
    private ConcordanceCli $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['conc_cli_log'] = [];
        $GLOBALS['conc_cli_formatted'] = null;
        $GLOBALS['conc_options'] = [];
        $this->client = $this->createMock(ApiClient::class);
        $this->cache = $this->createMock(ApiCache::class);
        $this->cli = new ConcordanceCli($this->client, $this->cache);
    }

    private function sampleGroups(): array
    {
        return [
            ['id' => 1, 'groupName' => 'Alpha', 'intergroupId' => 1, 'day' => 'Monday'],
            ['id' => 2, 'groupName' => 'Beta', 'intergroupId' => 2, 'day' => 'Tuesday'],
        ];
    }

    // ── list ─────────────────────────────────────────────────────────────

    public function testListGroupsFromCacheAndFormats(): void
    {
        $this->cache->method('getGroups')->willReturn($this->sampleGroups());
        $this->cli->list_groups([], []);
        $this->assertNotNull($GLOBALS['conc_cli_formatted']);
        $this->assertCount(2, $GLOBALS['conc_cli_formatted']['items']);
    }

    public function testListGroupsNoCacheUsesClient(): void
    {
        $this->client->expects($this->once())->method('getGroups')->willReturn($this->sampleGroups());
        $this->cli->list_groups([], ['no-cache' => true]);
        $this->assertNotNull($GLOBALS['conc_cli_formatted']);
    }

    public function testListGroupsErrorsOnWpError(): void
    {
        $this->cache->method('getGroups')->willReturn(new WP_Error('e', 'failed'));
        $this->expectException(ConcordanceCliExit::class);
        $this->cli->list_groups([], []);
    }

    public function testListGroupsWarnsWhenEmpty(): void
    {
        $this->cache->method('getGroups')->willReturn([]);
        $this->cli->list_groups([], []);
        $this->assertSame('warning', $GLOBALS['conc_cli_log'][0][0]);
    }

    public function testListGroupsFiltersByIntergroupSortsAndLimits(): void
    {
        $this->cache->method('getGroups')->willReturn($this->sampleGroups());
        $this->cli->list_groups([], ['intergroup' => 1, 'sort' => 'day', 'limit' => 5]);
        $this->assertCount(1, $GLOBALS['conc_cli_formatted']['items']);
    }

    public function testListGroupsWarnsWhenIntergroupFilterEmpties(): void
    {
        $this->cache->method('getGroups')->willReturn($this->sampleGroups());
        $this->cli->list_groups([], ['intergroup' => 999]);
        $this->assertSame('warning', $GLOBALS['conc_cli_log'][0][0]);
    }

    // ── get ──────────────────────────────────────────────────────────────

    public function testGetErrorsWithoutId(): void
    {
        $this->expectException(ConcordanceCliExit::class);
        $this->cli->get([], []);
    }

    public function testGetErrorsOnWpError(): void
    {
        $this->client->method('getGroup')->willReturn(new WP_Error('e', 'bad'));
        $this->expectException(ConcordanceCliExit::class);
        $this->cli->get(['42'], []);
    }

    public function testGetWarnsWhenNotFound(): void
    {
        $this->client->method('getGroup')->willReturn([]);
        $this->cli->get(['42'], []);
        $this->assertSame('warning', $GLOBALS['conc_cli_log'][0][0]);
    }

    public function testGetFormatsSingleGroup(): void
    {
        $this->client->method('getGroup')->willReturn(['id' => 42, 'groupName' => 'Gamma']);
        $this->cli->get(['42'], ['format' => 'json']);
        $this->assertSame('json', $GLOBALS['conc_cli_formatted']['format']);
    }

    // ── test / flush / config / version ──────────────────────────────────

    public function testTestReportsSuccess(): void
    {
        $this->client->method('getGroups')->willReturn($this->sampleGroups());
        $this->cli->test([], []);
        $kinds = array_column($GLOBALS['conc_cli_log'], 0);
        $this->assertContains('success', $kinds);
    }

    public function testTestErrorsOnFailure(): void
    {
        $this->client->method('getGroups')->willReturn(new WP_Error('e', 'down'));
        $this->expectException(ConcordanceCliExit::class);
        $this->cli->test([], []);
    }

    public function testFlushCacheReportsCount(): void
    {
        $this->cache->method('flush')->willReturn(3);
        $this->cli->flush_cache([], []);
        $this->assertSame('success', $GLOBALS['conc_cli_log'][0][0]);
    }

    public function testConfigFormatsSettings(): void
    {
        $GLOBALS['conc_options']['concordance_api_key'] = (new \Concordance\Common\Encryption())->encrypt('abcdefghijklmnop');
        $this->cli->config([], []);
        $this->assertNotNull($GLOBALS['conc_cli_formatted']);
        $settings = array_column($GLOBALS['conc_cli_formatted']['items'], 'Setting');
        $this->assertContains('API Key', $settings);
    }

    public function testConfigWithNoApiKey(): void
    {
        $this->cli->config([], []);
        $this->assertNotNull($GLOBALS['conc_cli_formatted']);
    }

    public function testVersionLogs(): void
    {
        $this->cli->version([], []);
        $this->assertSame('log', $GLOBALS['conc_cli_log'][0][0]);
    }
}
