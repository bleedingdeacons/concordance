<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use BleedingDeacons\WpMocks\Doubles\FakeWpdb;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use WP_Error;

#[CoversClass(\Concordance\Api\ApiCache::class)]
class ApiCacheTest extends TestCase
{
    /** @var ApiClient&MockObject */
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

    public function testFlushAdvancesTheCacheGeneration(): void
    {
        $this->assertTrue($this->cache->flush());
        $this->assertSame(2, WpState::$options['concordance_cache_version']);

        $this->assertTrue($this->cache->flush());
        $this->assertSame(3, WpState::$options['concordance_cache_version']);
    }

    public function testFlushTouchesNoDatabaseRows(): void
    {
        $this->cache->flush();

        // It used to DELETE the transient rows straight out of wp_options,
        // which cleared nothing once a persistent object cache moved
        // transients out of the database -- and reported success while doing
        // it.
        $this->assertSame([], $this->wpdb->queries);
    }

    public function testAFlushedEntryIsNotServedAgain(): void
    {
        $this->client->expects($this->exactly(2))->method('getGroup')->with('42')->willReturn(['id' => 42]);

        $this->assertSame(['id' => 42], $this->cache->getGroup('42', 600));
        $this->assertSame(['id' => 42], $this->cache->getGroup('42', 600)); // served from cache

        $this->cache->flush();

        // The entry is still in the transient store, and unreachable: the key
        // it was written under belongs to the previous generation.
        $this->assertNotEmpty(WpState::$transients);
        $this->assertSame(['id' => 42], $this->cache->getGroup('42', 600));
    }

    public function testKeysCarryTheGeneration(): void
    {
        WpState::$options['concordance_cache_version'] = 7;
        $this->client->method('getGroup')->willReturn(['id' => 1]);

        $this->cache->getGroup('1', 600);

        $keys = array_keys(WpState::$transients);
        $this->assertCount(1, $keys);
        $this->assertStringStartsWith('concordance_v7_group_', $keys[0]);
    }

    public function testAMangledGenerationOptionReadsAsTheFirst(): void
    {
        WpState::$options['concordance_cache_version'] = 'nonsense';
        $this->client->method('getGroup')->willReturn(['id' => 1]);

        $this->cache->getGroup('1', 600);

        $keys = array_keys(WpState::$transients);
        $this->assertStringStartsWith('concordance_v1_group_', $keys[0]);
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
