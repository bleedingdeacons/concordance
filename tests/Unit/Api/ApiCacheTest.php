<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers \Concordance\Api\ApiCache
 */
class ApiCacheTest extends TestCase
{
    /** @var ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;
    private ApiCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['conc_options'] = [];
        $GLOBALS['conc_transients'] = [];
        $GLOBALS['conc_wpdb_queries'] = [];
        unset($GLOBALS['conc_wpdb_query_result']);
        $this->client = $this->createMock(ApiClient::class);
        $this->cache = new ApiCache($this->client);
    }

    public function testGetGroupsBypassesCacheWhenTtlZero(): void
    {
        $this->client->method('getGroups')->willReturn([['a' => 1]]);
        $result = $this->cache->getGroups([], 0);
        $this->assertSame([['a' => 1]], $result);
        $this->assertSame([], $GLOBALS['conc_transients']); // nothing cached
    }

    public function testGetGroupsStoresAndServesFromCache(): void
    {
        $this->client->expects($this->once())->method('getGroups')->willReturn([['a' => 1]]);

        // First call: miss → fetch → store.
        $first = $this->cache->getGroups(['page' => 1], 600);
        $this->assertSame([['a' => 1]], $first);
        $this->assertNotSame([], $GLOBALS['conc_transients']);

        // Second call: hit → no second client call (expects once).
        $second = $this->cache->getGroups(['page' => 1], 600);
        $this->assertSame([['a' => 1]], $second);
    }

    public function testGetGroupsDoesNotCacheWpError(): void
    {
        $this->client->method('getGroups')->willReturn(new WP_Error('e', 'm'));
        $result = $this->cache->getGroups([], 600);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame([], $GLOBALS['conc_transients']);
    }

    public function testGetGroupsUsesStoredTtlOptionByDefault(): void
    {
        $GLOBALS['conc_options']['concordance_cache_ttl'] = 0; // disables caching
        $this->client->method('getGroups')->willReturn([['x' => 1]]);
        $this->cache->getGroups();
        $this->assertSame([], $GLOBALS['conc_transients']);
    }

    public function testGetGroupCachesAndServes(): void
    {
        $this->client->expects($this->once())->method('getGroup')->with('42')->willReturn(['id' => 42]);

        $this->assertSame(['id' => 42], $this->cache->getGroup('42', 600));
        $this->assertSame(['id' => 42], $this->cache->getGroup('42', 600)); // cache hit
    }

    public function testGetGroupBypassesCacheWhenTtlZero(): void
    {
        $this->client->method('getGroup')->willReturn(['id' => 9]);
        $this->assertSame(['id' => 9], $this->cache->getGroup(9, 0));
    }

    public function testGetGroupDoesNotCacheWpError(): void
    {
        $this->client->method('getGroup')->willReturn(new WP_Error('e', 'm'));
        $this->assertInstanceOf(WP_Error::class, $this->cache->getGroup(9, 600));
        $this->assertSame([], $GLOBALS['conc_transients']);
    }

    public function testFlushRunsTheDeleteQuery(): void
    {
        $GLOBALS['conc_wpdb_query_result'] = 7;
        $this->assertSame(7, $this->cache->flush());
        $this->assertNotEmpty($GLOBALS['conc_wpdb_queries']);
    }

    public function testGetGroupsWrapsAnUnexpectedException(): void
    {
        $this->client->method('getGroups')->willThrowException(new \RuntimeException('boom'));
        $result = $this->cache->getGroups([], 600);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('concordance_cache_error', $result->get_error_code());
    }

    public function testGetGroupWrapsAnUnexpectedException(): void
    {
        $this->client->method('getGroup')->willThrowException(new \RuntimeException('boom'));
        $result = $this->cache->getGroup(9, 600);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('concordance_cache_error', $result->get_error_code());
    }
}
