<?php

declare(strict_types=1);

namespace Concordance\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Closure;
use RuntimeException;

/**
 * Class Container
 *
 * Lightweight PSR-11 dependency injection container.
 * Supports lazy factory registration, singleton resolution, and tagging.
 */
class Container implements ContainerInterface
{
    /** @var array<string, Closure> Registered factory callables keyed by service ID. */
    private array $factories = [];

    /** @var array<string, object> Resolved singleton instances keyed by service ID. */
    private array $instances = [];

    /**
     * Register a service factory.
     *
     * The factory receives this container as its only argument and
     * should return the fully constructed service instance.
     *
     * @param string  $id      Service identifier (typically a class name).
     * @param Closure $factory Factory callable.
     * @return void
     */
    public function register(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;

        // Clear any previously resolved instance so the new factory takes effect
        unset($this->instances[$id]);
    }

    /**
     * Finds an entry by its identifier and returns it.
     *
     * Services are resolved once (singleton) and cached for subsequent calls.
     *
     * @param string $id Service identifier.
     * @return mixed
     * @throws NotFoundException  If no factory is registered for the given ID.
     * @throws ContainerException If the factory throws during resolution.
     */
    public function get(string $id): mixed
    {
        // Return cached singleton
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new NotFoundException(
                sprintf('Service "%s" is not registered in the container.', $id)
            );
        }

        try {
            $this->instances[$id] = ($this->factories[$id])($this);
        } catch (\Throwable $e) {
            throw new ContainerException(
                sprintf('Error resolving service "%s": %s', $id, $e->getMessage()),
                0,
                $e
            );
        }

        return $this->instances[$id];
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Service identifier.
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
}

/**
 * Exception thrown when a requested service is not found in the container.
 */
class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}

/**
 * Exception thrown when an error occurs during service resolution.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}