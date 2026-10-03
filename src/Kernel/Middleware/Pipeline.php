<?php

declare(strict_types=1);

namespace App\Kernel\Middleware;

use App\Kernel\Container;
use App\Kernel\Exception\ContainerException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use Closure;

/**
 * Runs a list of middleware around a core handler, first entry outermost.
 *
 * Entries are middleware instances, class names (resolved through the container) or
 * `[ClassName::class, ['param' => value]]` (built with `Container::make`, e.g. rate-limit settings).
 */
final class Pipeline
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param list<MiddlewareInterface|string|array{0: string, 1: array<string, mixed>}> $middleware
     * @param Closure(Request): Response $core
     * @throws ContainerException
     */
    public function run(array $middleware, Request $request, Closure $core): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $entry) {
            $instance = $this->resolve($entry);
            $next = static fn (Request $r): Response => $instance->handle($r, $next);
        }

        return $next($request);
    }

    /**
     * @param MiddlewareInterface|string|array{0: string, 1: array<string, mixed>} $entry
     */
    private function resolve(MiddlewareInterface|string|array $entry): MiddlewareInterface
    {
        if ($entry instanceof MiddlewareInterface) {
            return $entry;
        }
        $instance = is_array($entry)
            ? $this->container->make($entry[0], $entry[1])
            : $this->container->get($entry);
        if (!$instance instanceof MiddlewareInterface) {
            throw new ContainerException(sprintf('%s is not a middleware.', is_array($entry) ? $entry[0] : $entry));
        }

        return $instance;
    }
}
