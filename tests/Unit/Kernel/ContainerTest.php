<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Container;
use App\Kernel\Exception\ContainerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

interface ContainerTestGreeter
{
    public function greet(): string;
}

final class ContainerTestEnglish implements ContainerTestGreeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class ContainerTestConsumer
{
    public function __construct(public readonly ContainerTestGreeter $greeter, public readonly int $times = 2)
    {
    }
}

final class ContainerTestNeedsScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

final class ContainerTestCycleA
{
    public function __construct(public readonly ContainerTestCycleB $b)
    {
    }
}

final class ContainerTestOptionalClosure
{
    public function __construct(public readonly ?\Closure $resolver = null)
    {
    }
}

final class ContainerTestCycleB
{
    public function __construct(public readonly ContainerTestCycleA $a)
    {
    }
}

/**
 * Autowiring, factories, singletons, make() overrides and call().
 */
#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    public function testAutowiresThroughInterfaceFactoryAndKeepsSingletons(): void
    {
        $c = new Container();
        $c->factory(ContainerTestGreeter::class, static fn (): ContainerTestGreeter => new ContainerTestEnglish());

        $consumer = $c->get(ContainerTestConsumer::class);

        self::assertSame('hello', $consumer->greeter->greet());
        self::assertSame(2, $consumer->times);
        self::assertSame($consumer, $c->get(ContainerTestConsumer::class));
        self::assertSame($c, $c->get(Container::class));
    }

    public function testMakeBuildsFreshInstancesWithNamedOverrides(): void
    {
        $c = new Container();
        $c->instance(ContainerTestGreeter::class, new ContainerTestEnglish());

        $a = $c->make(ContainerTestConsumer::class, ['times' => 5]);
        $b = $c->make(ContainerTestConsumer::class);

        self::assertInstanceOf(ContainerTestConsumer::class, $a);
        self::assertSame(5, $a->times);
        self::assertNotSame($a, $b);
    }

    public function testUnboundInterfaceAndScalarCannotBeResolved(): void
    {
        $c = new Container();

        $this->expectException(ContainerException::class);
        $c->get(ContainerTestConsumer::class);
    }

    public function testScalarWithoutDefaultFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get(ContainerTestNeedsScalar::class);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular');
        (new Container())->get(ContainerTestCycleA::class);
    }

    public function testUnknownIdFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get('No\\Such\\Thing');
    }

    public function testCallResolvesByNameAndByType(): void
    {
        $c = new Container();
        $c->instance(ContainerTestGreeter::class, new ContainerTestEnglish());

        $result = $c->call(
            static fn (ContainerTestGreeter $greeter, string $name): string => $greeter->greet() . ' ' . $name,
            ['name' => 'bob'],
        );
        $viaMethod = $c->call([new ContainerTestEnglish(), 'greet']);

        self::assertSame('hello bob', $result);
        self::assertSame('hello', $viaMethod);
    }

    public function testNonInstantiableTypesFallBackToTheParameterDefault(): void
    {
        $c = new Container();

        self::assertFalse($c->has(\Closure::class));
        self::assertNull($c->get(ContainerTestOptionalClosure::class)->resolver);
    }
}
