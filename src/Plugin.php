<?php

declare(strict_types=1);

namespace Concordance;

if (!defined('ABSPATH')) {
    exit;
}

use Concordance\Api\ApiClient;
use Concordance\Api\ApiCache;
use Concordance\Cli\ConcordanceCli;
use Concordance\Common\Encryption;
use Concordance\Core\Container;
use Concordance\Managers\GroupListingManager;
use Concordance\Admin\SettingsAdmin;
use Concordance\Admin\GroupListings\GroupListingDashboard;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function add_action;
use function is_admin;

/**
 * Main Concordance Plugin Class
 */
class Plugin
{
    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialize the plugin with its own PSR-11 container.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $container = new Container();

        self::registerServices($container);

        self::$container = $container;
        self::$initialized = true;

        // Initialize GroupListingManager (needed for REST routes)
        self::$container->get(GroupListingManager::class);

        // Initialize admin services
        if (is_admin()) {
            self::$container->get(SettingsAdmin::class);
            self::$container->get(GroupListingDashboard::class);
        }

        // Initialize WP-CLI commands
        if (defined('WP_CLI') && \WP_CLI) {
            self::registerCliCommands();
        }
    }

    /**
     * Register all Concordance services in the container
     *
     * @param Container $container The dependency container
     * @return void
     */
    private static function registerServices(Container $container): void
    {
        // Register Encryption
        $container->register(Encryption::class, function (ContainerInterface $c) {
            return new Encryption();
        });

        // Register API Client
        $container->register(ApiClient::class, function (ContainerInterface $c) {
            return new ApiClient();
        });

        // Register API Cache
        $container->register(ApiCache::class, function (ContainerInterface $c) {
            return new ApiCache(
                $c->get(ApiClient::class)
            );
        });

        // Register Groups Manager
        $container->register(GroupListingManager::class, function (ContainerInterface $c) {
            return new GroupListingManager(
                $c->get(ApiClient::class),
                $c->get(ApiCache::class)
            );
        });

        // Register Settings Admin
        $container->register(SettingsAdmin::class, function (ContainerInterface $c) {
            return new SettingsAdmin(
                $c->get(ApiClient::class),
                $c->get(Encryption::class),
                $c->get(ApiCache::class)
            );
        });

        // Register Group Listing Dashboard
        $container->register(GroupListingDashboard::class, function (ContainerInterface $c) {
            return new GroupListingDashboard(
                $c->get(ApiCache::class)
            );
        });

        // Register CLI Command
        $container->register(ConcordanceCli::class, function (ContainerInterface $c) {
            return new ConcordanceCli(
                $c->get(ApiClient::class),
                $c->get(ApiCache::class)
            );
        });
    }

    /**
     * Register WP-CLI commands.
     *
     * @return void
     */
    private static function registerCliCommands(): void
    {
        $cli = self::$container->get(ConcordanceCli::class);
        \WP_CLI::add_command('concordance', $cli);
    }

    /**
     * Get the dependency container
     *
     * @return ContainerInterface
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Concordance Plugin not initialized');
        }
        return self::$container;
    }
}
