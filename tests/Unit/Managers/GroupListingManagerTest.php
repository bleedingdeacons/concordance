<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Managers;

use Concordance\Api\ApiCache;
use Concordance\Managers\GroupListingManager;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Concordance\Managers\GroupListingManager
 */
class GroupListingManagerTest extends TestCase
{
    /** @var ApiCache&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;
    private GroupListingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['conc_rest_routes'] = [];
        $this->cache = $this->createMock(ApiCache::class);
        $this->manager = new GroupListingManager($this->cache);
    }

    public function testRegisterRestRoutesRegistersBothRoutes(): void
    {
        $this->manager->registerRestRoutes();
        $this->assertContains('/groups', $GLOBALS['conc_rest_routes']);
        $this->assertContains('/groups/(?P<id>[\w-]+)', $GLOBALS['conc_rest_routes']);
    }

    public function testRegisteredValidateCallbacksBehave(): void
    {
        $this->manager->registerRestRoutes();
        $args = $GLOBALS['conc_rest_args']['/groups']['args'];

        $this->assertTrue($args['page']['validate_callback'](3));
        $this->assertFalse($args['page']['validate_callback'](0));
        $this->assertTrue($args['per_page']['validate_callback'](50));
        $this->assertFalse($args['per_page']['validate_callback'](200));
        $this->assertTrue($args['intergroup']['validate_callback'](0));
    }

    public function testRestGetGroupsReturnsMappedCollection(): void
    {
        $this->cache->method('getGroups')->willReturn([
            ['id' => 1, 'groupName' => 'Alpha'],
            ['id' => 2, 'groupName' => 'Beta'],
        ]);

        $request = new WP_REST_Request(['page' => 1, 'not_allowed' => 'x']);
        $response = $this->manager->restGetGroups($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $response->get_data());
    }

    public function testRestGetGroupsForwardsWpErrorStatus(): void
    {
        $this->cache->method('getGroups')->willReturn(
            new WP_Error('api', 'upstream', ['status' => 404])
        );

        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('upstream', $response->get_data()['error']);
    }

    public function testRestGetGroupsDefaultsErrorStatusTo502(): void
    {
        $this->cache->method('getGroups')->willReturn(new WP_Error('api', 'boom'));
        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        $this->assertSame(502, $response->get_status());
    }

    public function testRestGetGroupsHandlesException(): void
    {
        $this->cache->method('getGroups')->willThrowException(new \RuntimeException('kaboom'));
        $response = $this->manager->restGetGroups(new WP_REST_Request([]));
        $this->assertSame(500, $response->get_status());
    }

    public function testRestGetSingleGroupReturnsGroup(): void
    {
        $this->cache->method('getGroup')->with('42')->willReturn(['id' => 42, 'groupName' => 'Gamma']);
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '42']));
        $this->assertSame(200, $response->get_status());
        $this->assertIsArray($response->get_data());
    }

    public function testRestGetSingleGroupForwardsWpError(): void
    {
        $this->cache->method('getGroup')->willReturn(new WP_Error('api', 'nope', ['status' => 404]));
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '9']));
        $this->assertSame(404, $response->get_status());
    }

    public function testRestGetSingleGroupHandlesException(): void
    {
        $this->cache->method('getGroup')->willThrowException(new \RuntimeException('x'));
        $response = $this->manager->restGetSingleGroup(new WP_REST_Request(['id' => '9']));
        $this->assertSame(500, $response->get_status());
    }
}
