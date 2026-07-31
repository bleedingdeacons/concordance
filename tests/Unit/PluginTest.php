<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit;

use Concordance\Plugin;
use Concordance\Managers\GroupListingManager;
use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \Concordance\Plugin
 */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        // parent::setUp() clears WpState's options; is_admin() defaults to
        // true there, and Plugin::init's admin-only branch needs it off.
        WpState::$isAdmin = false;
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        parent::tearDown();
    }

    private function resetStatics(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $ref->getProperty('container')->setValue(null, null);
        $ref->getProperty('initialized')->setValue(null, false);
    }

    public function testInitBuildsContainerAndResolvesManager(): void
    {
        Plugin::init();

        $container = Plugin::getContainer();
        $this->assertInstanceOf(ContainerInterface::class, $container);
        $this->assertInstanceOf(GroupListingManager::class, $container->get(GroupListingManager::class));
    }

    public function testInitIsIdempotent(): void
    {
        Plugin::init();
        $first = Plugin::getContainer();
        Plugin::init();
        $this->assertSame($first, Plugin::getContainer());
    }

    public function testGetContainerThrowsBeforeInit(): void
    {
        $this->expectException(RuntimeException::class);
        Plugin::getContainer();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitRegistersCliCommandsWhenWpCliDefined(): void
    {
        define('WP_CLI', true);
        $GLOBALS['conc_cli_commands'] = [];

        Plugin::init();

        $this->assertContains('concordance', $GLOBALS['conc_cli_commands']);
    }
}
