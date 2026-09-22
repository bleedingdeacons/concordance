<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Core;

use Concordance\Core\Container;
use Concordance\Core\ContainerException;
use Concordance\Core\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/*
 * Tests for the PSR-11 Container.
 *
 * No WordPress dependencies — pure PHP.
 */

// ── Registration & resolution ───────────────────────────────────
it('resolves a registered service', function () {
    $container = new Container();
    $container->register('greeting', fn() => 'hello');

    expect($container->get('greeting'))->toBe('hello');
});

it('resolves a singleton to the same instance every time', function () {
    $container = new Container();
    $container->register('obj', fn() => new \stdClass());

    $first = $container->get('obj');
    $second = $container->get('obj');

    expect($first)->toBe($second);
});

it('passes the container to the factory', function () {
    $container = new Container();
    $container->register('dep', fn() => 'dependency-value');
    $container->register('service', fn(Container $c) => 'got:' . $c->get('dep'));

    expect($container->get('service'))->toBe('got:dependency-value');
});

it('calls the factory lazily, not at registration', function () {
    $called = false;
    $container = new Container();
    $container->register('lazy', function () use (&$called) {
        $called = true;
        return 'value';
    });

    expect($called)->toBeFalse('Factory should not be called at registration time');

    $container->get('lazy');
    expect($called)->toBeTrue();
});

// ── has() ───────────────────────────────────────────────────────
it('has a registered service', function () {
    $container = new Container();
    $container->register('exists', fn() => true);

    expect($container->has('exists'))->toBeTrue();
});

it('does not have an unregistered service', function () {
    $container = new Container();

    expect($container->has('nope'))->toBeFalse();
});

it('still has a service after resolving it', function () {
    $container = new Container();
    $container->register('svc', fn() => 'val');
    $container->get('svc');

    expect($container->has('svc'))->toBeTrue();
});

// ── Re-registration ─────────────────────────────────────────────
it('clears the cached instance when a service is re-registered', function () {
    $container = new Container();
    $container->register('svc', fn() => 'first');

    expect($container->get('svc'))->toBe('first');

    $container->register('svc', fn() => 'second');

    expect($container->get('svc'))->toBe('second');
});

// ── Exceptions ──────────────────────────────────────────────────
it('throws NotFoundException for an unknown service', function () {
    $container = new Container();

    $container->get('unknown');
})->throws(NotFoundException::class, 'not registered');

it('makes NotFoundException implement the PSR interface', function () {
    $e = new NotFoundException('test');

    expect($e)->toBeInstanceOf(NotFoundExceptionInterface::class);
});

it('throws ContainerException when the factory throws', function () {
    $container = new Container();
    $container->register('broken', function () {
        throw new \RuntimeException('factory failed');
    });

    $container->get('broken');
})->throws(ContainerException::class, 'factory failed');

it('makes ContainerException implement the PSR interface', function () {
    $e = new ContainerException('test');

    expect($e)->toBeInstanceOf(ContainerExceptionInterface::class);
});

it('wraps the original exception in ContainerException', function () {
    $container = new Container();
    $original = new \RuntimeException('root cause');
    $container->register('broken', function () use ($original) {
        throw $original;
    });

    try {
        $container->get('broken');
        $this->fail('Expected ContainerException');
    } catch (ContainerException $e) {
        expect($e->getPrevious())->toBe($original);
    }
});
