<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Kernel\Exception\ContainerException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Small dependency-injection container: autowires classes by constructor type hints and honours
 * explicit factories (`config/services.php`). Services are singletons by default.
 *
 * Scalar parameters cannot be autowired: give them a default value or register a factory.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
    }

    /**
     * Register a factory for `$id` (usually a class or interface name).
     *
     * @param Closure(self): mixed $factory
     */
    public function factory(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        if (isset($this->instances[$id]) || isset($this->factories[$id])) {
            return true;
        }

        // Closure, abstract classes and the like exist but cannot be built: a parameter of such a type
        // must fall back to its default value instead of failing.
        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     * @throws ContainerException
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf('Circular dependency while resolving %s.', $id));
        }
        $this->resolving[$id] = true;
        try {
            $instance = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->build($id, []);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->instances[$id] = $instance;
    }

    /**
     * Build a fresh (non-shared) instance, overriding constructor parameters by name.
     *
     * @param array<string, mixed> $parameters
     * @throws ContainerException
     */
    public function make(string $class, array $parameters = []): object
    {
        return $this->build($class, $parameters);
    }

    /**
     * Call a function or method resolving its parameters from `$parameters` (by name) and the container.
     *
     * @param callable|array{0: object|string, 1: string} $callable
     * @param array<string, mixed> $parameters
     * @throws ContainerException
     */
    public function call(callable|array $callable, array $parameters = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $object = is_string($target) ? $this->get($target) : $target;
            try {
                $reflection = new ReflectionMethod($object, $method);
            } catch (ReflectionException $e) {
                throw new ContainerException($e->getMessage(), 0, $e);
            }
            $arguments = $this->resolveParameters($reflection, $parameters);

            return $reflection->invokeArgs($object, $arguments);
        }

        $reflection = new ReflectionFunction(Closure::fromCallable($callable));

        return $reflection->invokeArgs($this->resolveParameters($reflection, $parameters));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function build(string $class, array $parameters): object
    {
        if (!class_exists($class)) {
            throw new ContainerException(sprintf('Cannot resolve %s: no such class and no factory registered.', $class));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Cannot instantiate %s: bind it in config/services.php.', $class));
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        return $reflection->newInstanceArgs($this->resolveParameters($constructor, $parameters));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<mixed>
     */
    private function resolveParameters(ReflectionFunctionAbstract $function, array $overrides): array
    {
        $arguments = [];
        foreach ($function->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($function, $parameter, $overrides);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveParameter(ReflectionFunctionAbstract $function, ReflectionParameter $parameter, array $overrides): mixed
    {
        $name = $parameter->getName();
        if (array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if ($this->has($type->getName())) {
                return $this->get($type->getName());
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s of %s: register a factory or give it a default value.',
            $name,
            $function->getName(),
        ));
    }
}
