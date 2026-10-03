<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Kernel\Exception\ConfigException;

/**
 * Read-only view over environment variables with typed getters.
 *
 * Config files receive an instance of this class; nothing else in the app reads `getenv()`.
 */
final class Env
{
    /**
     * @param array<string, string> $vars
     */
    public function __construct(private readonly array $vars)
    {
    }

    /**
     * Snapshot of the process environment (only string values).
     */
    public static function fromProcess(): self
    {
        $vars = [];
        foreach (getenv() as $key => $value) {
            $vars[$key] = $value;
        }

        return new self($vars);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->vars;
    }

    public function has(string $key): bool
    {
        return isset($this->vars[$key]) && $this->vars[$key] !== '';
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->has($key) ? $this->vars[$key] : $default;
    }

    /**
     * @throws ConfigException when the variable is absent or empty
     */
    public function required(string $key): string
    {
        if (!$this->has($key)) {
            throw new ConfigException(sprintf('Missing required environment variable %s.', $key));
        }

        return $this->vars[$key];
    }

    public function int(string $key, int $default = 0): int
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = filter_var($this->vars[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new ConfigException(sprintf('Environment variable %s must be an integer.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return in_array(strtolower($this->vars[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Comma-separated list, trimmed, empty items dropped.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        if (!$this->has($key)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->vars[$key])),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
