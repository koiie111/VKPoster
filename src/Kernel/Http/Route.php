<?php

declare(strict_types=1);

namespace App\Kernel\Http;

/**
 * A single route definition. Fluent setters return the same instance (routes are built once at boot).
 *
 * Handler is `[ControllerClass::class, 'method']`. Middleware entries are class names or
 * `[ClassName::class, ['param' => value]]` (constructor overrides, see `Container::make`).
 */
final class Route
{
    private ?string $name = null;
    private bool $csrf = true;

    /**
     * @param list<string> $methods
     * @param array{0: class-string, 1: string} $handler
     * @param list<string|array{0: string, 1: array<string, mixed>}> $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array $handler,
        public array $middleware = [],
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    /**
     * Opt out of CSRF verification (webhooks and token-authenticated API only).
     */
    public function withoutCsrf(): self
    {
        $this->csrf = false;

        return $this;
    }

    public function requiresCsrf(): bool
    {
        return $this->csrf;
    }

    /**
     * @param string|array{0: string, 1: array<string, mixed>} ...$middleware
     */
    public function middleware(string|array ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }
}
