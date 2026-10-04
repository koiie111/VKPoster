<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Exception\HttpException;
use InvalidArgumentException;

/**
 * Route table and matcher.
 *
 * Patterns: `/posts/{id}` (segment, any chars but `/`) or `/posts/{id:[0-9A-Z]{26}}` (own regex).
 * Groups add a path prefix and middleware. HEAD falls back to GET. Unknown path → 404;
 * known path with another method → 405 with an `Allow` header.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    private string $prefix = '';

    /** @var list<string|array{0: string, 1: array<string, mixed>}> */
    private array $groupMiddleware = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function get(string $pattern, array $handler): Route
    {
        return $this->add(['GET'], $pattern, $handler);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function post(string $pattern, array $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function put(string $pattern, array $handler): Route
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function patch(string $pattern, array $handler): Route
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function delete(string $pattern, array $handler): Route
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $pattern, array $handler): Route
    {
        $route = new Route($methods, $this->prefix . $pattern, $handler, $this->groupMiddleware);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Register routes sharing a prefix and middleware.
     *
     * @param list<string|array{0: string, 1: array<string, mixed>}> $middleware
     * @param callable(self): void $routes
     */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;
        $this->prefix .= $prefix;
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];
        try {
            $routes($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * Match a request. Returns null (with `$allowed` filled) when nothing matches.
     *
     * @param list<string> $allowed methods allowed for the path, set when the result is null
     * @return array{route: Route, params: array<string, string>}|null
     */
    public function match(string $method, string $path, array &$allowed = []): ?array
    {
        $allowed = [];
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        foreach ($this->routes as $route) {
            if (preg_match($this->compile($route->pattern), $path, $matches) !== 1) {
                continue;
            }
            if (array_intersect($lookup, $route->methods) !== []) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                return ['route' => $route, 'params' => $params];
            }
            $allowed = [...$allowed, ...$route->methods];
        }
        $allowed = array_values(array_unique($allowed));

        return null;
    }

    /**
     * @return array{route: Route, params: array<string, string>}
     * @throws HttpException 404 or 405
     */
    public function dispatch(string $method, string $path): array
    {
        $allowed = [];
        $match = $this->match($method, $path, $allowed);
        if ($match !== null) {
            return $match;
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', $allowed)]);
        }

        throw new HttpException(404, 'Not found');
    }

    /**
     * Every registered route, in registration order (used by tests that audit the whole route table).
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Register names for `url()`. Call after all routes are defined.
     */
    public function indexNames(): void
    {
        $this->named = [];
        foreach ($this->routes as $route) {
            $name = $route->routeName();
            if ($name !== null) {
                $this->named[$name] = $route;
            }
        }
    }

    /**
     * Build a URL for a named route.
     *
     * @param array<string, scalar> $params path parameters
     * @param array<string, scalar> $query
     * @throws InvalidArgumentException unknown route, missing or malformed parameter
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        if ($this->named === []) {
            $this->indexNames();
        }
        $route = $this->named[$name] ?? throw new InvalidArgumentException(sprintf('Unknown route "%s".', $name));
        $url = preg_replace_callback(
            '/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/',
            static function (array $m) use ($params, $name): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new InvalidArgumentException(sprintf('Route "%s" needs parameter "%s".', $name, $m[1]));
                }
                $value = (string) $params[$m[1]];
                $regex = $m[2] ?? '[^/]+';
                if (preg_match('#^' . str_replace('#', '\#', $regex) . '$#', $value) !== 1) {
                    throw new InvalidArgumentException(sprintf('Parameter "%s" of route "%s" is malformed.', $m[1], $name));
                }

                return rawurlencode($value);
            },
            $route->pattern,
        ) ?? $route->pattern;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function compile(string $pattern): string
    {
        $regex = '';
        $offset = 0;
        preg_match_all('/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', $pattern, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($found as $m) {
            $regex .= preg_quote(substr($pattern, $offset, $m[0][1] - $offset), '#');
            $regex .= '(?P<' . $m[1][0] . '>' . (isset($m[2]) ? $m[2][0] : '[^/]+') . ')';
            $offset = $m[0][1] + strlen($m[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $offset), '#');

        return '#^' . $regex . '$#';
    }
}
