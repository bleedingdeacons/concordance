<?php

declare(strict_types=1);

namespace Concordance\Tests\Unit\Core;

use Concordance\Core\Container;
use Concordance\Core\ContainerException;
use Concordance\Core\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PSR-11 Container.
 *
 * No WordPress dependencies — pure PHP.
 */
class ContainerTest extends TestCase
{
    // ── Registration & resolution ───────────────────────────────────

    /** @test */
    public function get_resolves_a_registered_service(): void
    {
        $container = new Container();
        $container->register('greeting', fn() => 'hello');

        $this->assertSame('hello', $container->get('greeting'));
    }

    /** @test */
    public function get_resolves_singleton_returning_same_instance(): void
    {
        $container = new Container();
        $container->register('obj', fn() => new \stdClass());

        $first = $container->get('obj');
        $second = $container->get('obj');

        $this->assertSame($first, $second);
    }

    /** @test */
    public function factory_receives_container_as_argument(): void
    {
        $container = new Container();
        $container->register('dep', fn() => 'dependency-value');
        $container->register('service', fn(Container $c) => 'got:' . $c->get('dep'));

        $this->assertSame('got:dependency-value', $container->get('service'));
    }

    /** @test */
    public function factory_is_called_lazily_not_at_registration(): void
    {
        $called = false;
        $container = new Container();
        $container->register('lazy', function () use (&$called) {
            $called = true;
            return 'value';
        });

        $this->assertFalse($called, 'Factory should not be called at registration time');

        $container->get('lazy');
        $this->assertTrue($called);
    }

    // ── has() ───────────────────────────────────────────────────────

    /** @test */
    public function has_returns_true_for_registered_service(): void
    {
        $container = new Container();
        $container->register('exists', fn() => true);

        $this->assertTrue($container->has('exists'));
    }

    /** @test */
    public function has_returns_false_for_unregistered_service(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('nope'));
    }

    /** @test */
    public function has_returns_true_after_resolution(): void
    {
        $container = new Container();
        $container->register('svc', fn() => 'val');
        $container->get('svc');

        $this->assertTrue($container->has('svc'));
    }

    // ── Re-registration ─────────────────────────────────────────────

    /** @test */
    public function re_registering_clears_cached_instance(): void
    {
        $container = new Container();
        $container->register('svc', fn() => 'first');

        $this->assertSame('first', $container->get('svc'));

        $container->register('svc', fn() => 'second');

        $this->assertSame('second', $container->get('svc'));
    }

    // ── Exceptions ──────────────────────────────────────────────────

    /** @test */
    public function get_throws_NotFoundException_for_unknown_service(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $container->get('unknown');
    }

    /** @test */
    public function NotFoundException_implements_psr_interface(): void
    {
        $e = new NotFoundException('test');

        $this->assertInstanceOf(\Psr\Container\NotFoundExceptionInterface::class, $e);
    }

    /** @test */
    public function get_throws_ContainerException_when_factory_throws(): void
    {
        $container = new Container();
        $container->register('broken', function () {
            throw new \RuntimeException('factory failed');
        });

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/factory failed/');

        $container->get('broken');
    }

    /** @test */
    public function ContainerException_implements_psr_interface(): void
    {
        $e = new ContainerException('test');

        $this->assertInstanceOf(\Psr\Container\ContainerExceptionInterface::class, $e);
    }

    /** @test */
    public function ContainerException_wraps_original_exception(): void
    {
        $container = new Container();
        $original = new \RuntimeException('root cause');
        $container->register('broken', function () use ($original) {
            throw $original;
        });

        try {
            $container->get('broken');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }
}
