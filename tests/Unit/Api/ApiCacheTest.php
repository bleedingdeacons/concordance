<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use BleedingDeacons\WpMocks\Doubles\FakeWpdb;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use WP_Error;

/**
 * @covers \Concordance\Api\ApiCache
 */
class ApiCacheTest extends TestCase
{
    /** @var ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;
    private ApiCache $cache;

    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp() clears WpState, so options and transients start empty.
        $this->wpdb = $GLOBALS['wpdb'];
        $this->wpdb->reset();
        $this->client = $this->createMock(ApiClient::class);
        $this->cache = new ApiCache($this->client);
    }

    public function testGetGroupsBypassesCacheWhenTtlZero(): void
    {
        $this->client->method('getGroups')->willReturn([['a' => 1]]);
        $result = $this->cache->getGroups([], 0);
        $this->assertSame([['a' => 1]], $result);
        $this->assertSame([], WpState::$transients); // nothing cached
    }

    public function testGetGroupsStoresAndServesFromCache(): void
    {
        $this->client->expects($this->once())->method('getGroups')->willReturn([['a' => 1]]);

        // First call: miss → fetch → store.
        $first = $this->cache->getGroups(['page' => 1], 600);
        $this->assertSame([['a' => 1]], $first);
        $this->assertNotSame([], WpState::$transients);

        // Second call: hit → no second client call (expects once).
        $second = $this->cache->getGroups(['page' => 1], 600);
        $this->assertSame([['a' => 1]], $second);
    }

    public function testGetGroupsDoesNotCacheWpError(): void
    {
        $this->client->method('getGroups')->willReturn(new WP_Error('e', 'm'));
        $result = $this->cache->getGroups([], 600);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame([], WpState::$transients);
    }

    public function testGetGroupsUsesStoredTtlOptionByDefault(): void
    {
        WpState::$options['concordance_cache_ttl'] = 0; // disables caching
        $this->client->method('getGroups')->willReturn([['x' => 1]]);
        $this->cache->getGroups();
        $this->assertSame([], WpState::$transients);
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
        $this->assertSame([], WpState::$transients);
    }

    public function testFlushRunsTheDeleteQuery(): void
    {
        $this->wpdb->queryResult = 7;
        $this->assertSame(7, $this->cache->flush());
        $this->assertNotEmpty($this->wpdb->queries);
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
