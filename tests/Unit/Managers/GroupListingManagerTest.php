<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Managers;

use BleedingDeacons\WpMocks\WpState;
use Concordance\Api\ApiCache;
use Concordance\Managers\GroupListingManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/*
 * Tests for GroupListingManager's REST routes and their callbacks.
 */

covers(\Concordance\Managers\GroupListingManager::class);

beforeEach(function () {
    // The TestCase's setUp() clears WpState, including $restRoutes.
    $this->cache = $this->createMock(ApiCache::class);
    $this->manager = new GroupListingManager($this->cache);
});

describe('route registration', function () {
    it('registers both routes', function () {
        $this->manager->registerRestRoutes();

        $routes = array_column(WpState::$restRoutes, 'route');
        expect($routes)->toContain('/groups')
            ->toContain('/groups/(?P<id>[\w-]+)');
    });

    it('registers validate callbacks that behave', function () {
        $this->manager->registerRestRoutes();
        $routes = array_column(WpState::$restRoutes, null, 'route');
        $args = $routes['/groups']['args']['args'];

        expect($args['page']['validate_callback'](3))->toBeTrue()
            ->and($args['page']['validate_callback'](0))->toBeFalse()
            ->and($args['per_page']['validate_callback'](50))->toBeTrue()
            ->and($args['per_page']['validate_callback'](200))->toBeFalse()
            ->and($args['intergroup']['validate_callback'](0))->toBeTrue();
    });
});

describe('restGetGroups', function () {
    it('returns the mapped collection', function () {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'Alpha'],
            ['id' => 2, 'groupName' => 'Beta'],
        ]);

        $request = new WP_REST_Request(['page' => 1, 'not_allowed' => 'x']);
        $response = $this->manager->restGetGroups($request);

        expect($response)->toBeInstanceOf(WP_REST_Response::class)
            ->and($response->get_status())->toBe(200)
            ->and($response->get_data())->toHaveCount(2);
    });

    it('forwards the status of a WP_Error', function () {
        $this->cache->method('getGroups')->willReturn(
            new WP_Error('api', 'upstream', ['status' => 404])
        );

        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        expect($response->get_status())->toBe(404)
            ->and($response->get_data()['error'])->toBe('upstream');
    });

    it('defaults the error status to 502', function () {
        $this->cache->method('getGroups')->willReturn(new WP_Error('api', 'boom'));
        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        expect($response->get_status())->toBe(502);
    });

    it('handles an exception', function () {
        $this->cache->method('getGroups')->willThrowException(new \RuntimeException('kaboom'));
        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        expect($response->get_status())->toBe(500);
    });
});

describe('restGetSingleGroup', function () {
    it('returns the group', function () {
        $this->cache->method('getGroup')->with('42')->willReturn(['id' => 42, 'groupName' => 'Gamma']);
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '42']));
        expect($response->get_status())->toBe(200)
            ->and($response->get_data())->toBeArray();
    });

    it('forwards a WP_Error', function () {
        $this->cache->method('getGroup')->willReturn(new WP_Error('api', 'nope', ['status' => 404]));
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '9']));
        expect($response->get_status())->toBe(404);
    });

    it('handles an exception', function () {
        $this->cache->method('getGroup')->willThrowException(new \RuntimeException('x'));
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '9']));
        expect($response->get_status())->toBe(500);
    });
});
