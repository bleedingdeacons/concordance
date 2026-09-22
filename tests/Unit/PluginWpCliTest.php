<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Concordance\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;

/**
 * Plugin::init() under WP-CLI.
 *
 * Kept as a PHPUnit class, not a Pest spec like the rest of the plugin's tests
 * in PluginTest. It defines WP_CLI, which cannot be undone once defined, so it
 * must run in a separate process — and Pest refuses process isolation
 * outright. Pest runs this class as it is.
 */
#[CoversClass(\Concordance\Plugin::class)]
class PluginWpCliTest extends TestCase
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

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testInitRegistersCliCommandsWhenWpCliDefined(): void
    {
        define('WP_CLI', true);
        $GLOBALS['conc_cli_commands'] = [];

        Plugin::init();

        $this->assertContains('concordance', $GLOBALS['conc_cli_commands']);
    }
}
