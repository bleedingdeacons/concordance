<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Concordance\Managers\GroupListingManager;
use Concordance\Plugin;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/*
 * Tests for the Plugin bootstrap: building the container once, and refusing
 * to hand it out before it exists.
 *
 * The WP-CLI registration test lives in PluginWpCliTest, a PHPUnit class: it
 * defines WP_CLI, so it needs a separate process, which Pest refuses.
 */

covers(\Concordance\Plugin::class);

function resetPluginStatics(): void
{
    $ref = new ReflectionClass(Plugin::class);
    $ref->getProperty('container')->setValue(null, null);
    $ref->getProperty('initialized')->setValue(null, false);
}

beforeEach(function () {
    resetPluginStatics();
    // The TestCase's setUp() clears WpState's options; is_admin() defaults to
    // true there, and Plugin::init's admin-only branch needs it off.
    WpState::$isAdmin = false;
});

afterEach(function () {
    resetPluginStatics();
});

it('builds the container on init and resolves the manager from it', function () {
    Plugin::init();

    $container = Plugin::getContainer();
    expect($container)->toBeInstanceOf(ContainerInterface::class)
        ->and($container->get(GroupListingManager::class))->toBeInstanceOf(GroupListingManager::class);
});

it('is idempotent across repeated inits', function () {
    Plugin::init();
    $first = Plugin::getContainer();
    Plugin::init();
    expect(Plugin::getContainer())->toBe($first);
});

it('throws when the container is requested before init', function () {
    Plugin::getContainer();
})->throws(RuntimeException::class);
