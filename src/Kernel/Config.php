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
        $url = parse_url($this->string('app.url'));
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !is_string($url['host'] ?? null) || $url['host'] === '') {
            throw new ConfigException('APP_URL must be an HTTPS URL in production.');
        }
        if (preg_match('/^[A-Za-z0-9+\/=_-]{32,128}$/D', $this->string('database.redis.password')) !== 1) {
            throw new ConfigException('REDIS_PASSWORD must be a strong 32-128 character secret in production.');
        }
        $proxies = $this->array('app.trusted_proxies');
        if ($proxies === []) {
            throw new ConfigException('TRUSTED_PROXIES must name the production reverse proxy.');
        }
        foreach ($proxies as $proxy) {
            $parts = is_string($proxy) ? explode('/', $proxy) : [];
            $ip = $parts[0] ?? '';
            $max = str_contains($ip, ':') ? 128 : 32;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || count($parts) > 2
                || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $max))) {
                throw new ConfigException('TRUSTED_PROXIES must contain explicit IPs or CIDRs.');
            }
            if (!self::privateNetwork($ip, isset($parts[1]) ? (int) $parts[1] : $max)) {
                throw new ConfigException('TRUSTED_PROXIES must stay inside loopback, private or link-local networks.');
            }
        }
        new \App\Kernel\HttpClient\EgressRoutes($this->env);
        if ($this->env->string('CRYPTO_BACKEND') !== 'vault') {
            throw new ConfigException('Production requires isolated CRYPTO_BACKEND=vault; local crypto is forbidden.');
        }
        foreach (['APP_KEY', 'APP_KEYS', 'PASSWORD_PEPPER_FILE', 'PASSWORD_PEPPER_PREVIOUS_FILES'] as $raw) {
            if ($this->env->has($raw)) {
                throw new ConfigException('Raw cryptographic key configuration is forbidden in production: ' . $raw);
            }
        }
        $this->env->required('VAULT_TRANSIT_URL');
        $this->env->required('VAULT_TOKEN_FILE');
        \App\Kernel\Security\VaultTransit::validateConfiguration(
            $this->env->required('VAULT_TRANSIT_URL'),
            $this->env->string('VAULT_TRANSIT_KEY', 'vkposter'),
            $this->env->required('VAULT_TOKEN_FILE'),
            $this->env->string('VAULT_CA_FILE')
        );
        if (strlen($this->env->string('EGRESS_IDENTITY_SALT')) < 32) {
            throw new ConfigException('Independent EGRESS_IDENTITY_SALT is required in production.');
        }
        foreach ($this->env->all() as $name => $value) {
            if (str_starts_with($name, 'DEV_') && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)) {
                throw new ConfigException(sprintf('%s must not be enabled when APP_ENV=production.', $name));
            }
        }
    }

    /** True when ip/prefix lies wholly inside a loopback, private, CGNAT or link-local network. */
    private static function privateNetwork(string $ip, int $prefix): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $networks = strlen($packed) === 4
            ? [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8], ['169.254.0.0', 16], ['100.64.0.0', 10]]
            : [['::1', 128], ['fc00::', 7], ['fe80::', 10]];
        foreach ($networks as [$base, $length]) {
            $baseBytes = (string) inet_pton($base);
            if ($prefix < $length) {
                continue;
            }
            $bytes = intdiv($length, 8);
            $bits = $length % 8;
            if (substr($packed, 0, $bytes) !== substr($baseBytes, 0, $bytes)) {
                continue;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ($bits === 0 || (ord($packed[$bytes]) & $mask) === (ord($baseBytes[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
