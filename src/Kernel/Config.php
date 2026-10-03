<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Kernel\Exception\ConfigException;
use App\Support\Fs;

/**
 * Application configuration: `config/*.php` files (each returns `fn (Env): array`) merged under
 * their file name, e.g. `config/app.php` is read as `app.*`.
 *
 * Loading fails fast on missing required variables and on settings that are unsafe in production
 * (`APP_DEBUG` on, or any truthy `DEV_*` flag).
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items, private readonly Env $env)
    {
    }

    /**
     * @throws ConfigException
     */
    public static function load(string $dir, Env $env): self
    {
        $items = [];
        foreach (Fs::glob(rtrim($dir, '/') . '/*.php') as $file) {
            $name = basename($file, '.php');
            if (in_array($name, ['routes', 'services', 'schedule'], true)) {
                continue; // code-style config, loaded by the application itself
            }
            $factory = require $file;
            if (!is_callable($factory)) {
                throw new ConfigException(sprintf('config/%s.php must return a callable.', $name));
            }
            $items[$name] = $factory($env);
        }
        $config = new self($items, $env);
        $config->assertSafeForEnvironment();

        return $config;
    }

    public function env(): Env
    {
        return $this->env;
    }

    /**
     * @param string $key dotted path, e.g. `app.debug`
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'production';
    }

    public function isLocal(): bool
    {
        return $this->string('app.env') === 'local';
    }

    private function assertSafeForEnvironment(): void
    {
        $envName = $this->string('app.env');
        if (!in_array($envName, ['local', 'testing', 'production'], true)) {
            throw new ConfigException('APP_ENV must be one of: local, testing, production.');
        }
        if ($envName !== 'production') {
            return;
        }
        if ($this->bool('app.debug')) {
            throw new ConfigException('APP_DEBUG must be off when APP_ENV=production.');
        }
        foreach ($this->env->all() as $name => $value) {
            if (str_starts_with($name, 'DEV_') && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)) {
                throw new ConfigException(sprintf('%s must not be enabled when APP_ENV=production.', $name));
            }
        }
    }
}
