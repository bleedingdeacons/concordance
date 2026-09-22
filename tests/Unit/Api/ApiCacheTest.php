<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Api;

use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiCache;
use Concordance\Api\ApiClient;
use WP_Error;

/*
 * Tests for ApiCache: the transient-backed cache in front of ApiClient, and
 * the generation counter that makes a flush work under any object cache.
 */

covers(\Concordance\Api\ApiCache::class);

beforeEach(function () {
    // The TestCase's setUp() clears WpState, so options and transients start empty.
    $this->wpdb = $GLOBALS['wpdb'];
    $this->wpdb->reset();
    $this->client = $this->createMock(ApiClient::class);
    $this->cache = new ApiCache($this->client);
});

describe('getGroups', function () {
    it('bypasses the cache when the TTL is zero', function () {
        $this->client->method('getGroups')->willReturn([['a' => 1]]);
        $result = $this->cache->getGroups([], 0);
        expect($result)->toBe([['a' => 1]])
            ->and(WpState::$transients)->toBe([]); // nothing cached
    });

    it('stores a response and serves it from the cache', function () {
        $this->client->expects($this->once())->method('getGroups')->willReturn([['a' => 1]]);

        // First call: miss → fetch → store.
        $first = $this->cache->getGroups(['page' => 1], 600);
        expect($first)->toBe([['a' => 1]])
            ->and(WpState::$transients)->not->toBe([]);

        // Second call: hit → no second client call (expects once).
        $second = $this->cache->getGroups(['page' => 1], 600);
        expect($second)->toBe([['a' => 1]]);
    });

    it('does not cache a WP_Error', function () {
        $this->client->method('getGroups')->willReturn(new WP_Error('e', 'm'));
        $result = $this->cache->getGroups([], 600);
        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and(WpState::$transients)->toBe([]);
    });

    it('uses the stored TTL option by default', function () {
        WpState::$options['concordance_cache_ttl'] = 0; // disables caching
        $this->client->method('getGroups')->willReturn([['x' => 1]]);
        $this->cache->getGroups();
        expect(WpState::$transients)->toBe([]);
    });

    it('wraps an unexpected exception', function () {
        $this->client->method('getGroups')->willThrowException(new \RuntimeException('boom'));
        $result = $this->cache->getGroups([], 600);
        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('concordance_cache_error');
    });
});

describe('getGroup', function () {
    it('caches a response and serves it', function () {
        $this->client->expects($this->once())->method('getGroup')->with('42')->willReturn(['id' => 42]);

        expect($this->cache->getGroup('42', 600))->toBe(['id' => 42])
            ->and($this->cache->getGroup('42', 600))->toBe(['id' => 42]); // cache hit
    });

    it('bypasses the cache when the TTL is zero', function () {
        $this->client->method('getGroup')->willReturn(['id' => 9]);
        expect($this->cache->getGroup(9, 0))->toBe(['id' => 9]);
    });

    it('does not cache a WP_Error', function () {
        $this->client->method('getGroup')->willReturn(new WP_Error('e', 'm'));
        expect($this->cache->getGroup(9, 600))->toBeInstanceOf(WP_Error::class)
            ->and(WpState::$transients)->toBe([]);
    });

    it('wraps an unexpected exception', function () {
        $this->client->method('getGroup')->willThrowException(new \RuntimeException('boom'));
        $result = $this->cache->getGroup(9, 600);
        expect($result)->toBeInstanceOf(WP_Error::class)
            ->and($result->get_error_code())->toBe('concordance_cache_error');
    });
});

describe('flush', function () {
    it('advances the cache generation', function () {
        expect($this->cache->flush())->toBeTrue()
            ->and(WpState::$options['concordance_cache_version'])->toBe(2);

        expect($this->cache->flush())->toBeTrue()
            ->and(WpState::$options['concordance_cache_version'])->toBe(3);
    });

    it('touches no database rows', function () {
        $this->cache->flush();

        // It used to DELETE the transient rows straight out of wp_options,
        // which cleared nothing once a persistent object cache moved
        // transients out of the database -- and reported success while doing
        // it.
        expect($this->wpdb->queries)->toBe([]);
    });

    it('does not serve a flushed entry again', function () {
        $this->client->expects($this->exactly(2))->method('getGroup')->with('42')->willReturn(['id' => 42]);

        expect($this->cache->getGroup('42', 600))->toBe(['id' => 42])
            ->and($this->cache->getGroup('42', 600))->toBe(['id' => 42]); // served from cache

        $this->cache->flush();

        // The entry is still in the transient store, and unreachable: the key
        // it was written under belongs to the previous generation.
        expect(WpState::$transients)->not->toBeEmpty()
            ->and($this->cache->getGroup('42', 600))->toBe(['id' => 42]);
    });
});

describe('cache keys', function () {
    it('puts the generation in every key', function () {
        WpState::$options['concordance_cache_version'] = 7;
        $this->client->method('getGroup')->willReturn(['id' => 1]);

        $this->cache->getGroup('1', 600);

        $keys = array_keys(WpState::$transients);
        expect($keys)->toHaveCount(1)
            ->and($keys[0])->toStartWith('concordance_v7_group_');
    });

    it('reads a mangled generation option as the first generation', function () {
        WpState::$options['concordance_cache_version'] = 'nonsense';
        $this->client->method('getGroup')->willReturn(['id' => 1]);

        $this->cache->getGroup('1', 600);

        $keys = array_keys(WpState::$transients);
        expect($keys[0])->toStartWith('concordance_v1_group_');
    });
});
