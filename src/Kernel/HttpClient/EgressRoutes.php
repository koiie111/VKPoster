<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use App\Kernel\Env;
use App\Kernel\Exception\ConfigException;

/** Provider-scoped transport policy. A tunnel is an operator-managed local proxy, never a global VPN. */
final class EgressRoutes
{
    public const PROVIDERS = ['telegram', 'vk', 'max', 'openai', 'tineye', 'replicate', 'anthropic', 'glitchtip'];
    private readonly string $identityKey;
    /** @var array<string, array{mode: string, proxy: string}> */
    private array $routes = [];

    public function __construct(Env $env)
    {
        $this->identityKey = $env->string('EGRESS_IDENTITY_SALT', 'egress-configuration-fingerprint-v1');
        foreach (self::PROVIDERS as $provider) {
            $prefix = 'EGRESS_' . strtoupper($provider);
            $mode = $env->string($prefix . '_MODE', 'direct');
            $url = $env->string($prefix . '_PROXY_URL');
            if (!in_array($mode, ['direct', 'http', 'socks5', 'tunnel'], true)) {
                throw new ConfigException('Invalid egress mode for ' . $provider . '.');
            }
            if ($mode !== 'direct') {
                try {
                    $parts = parse_url($url);
                } catch (\ValueError) {
                    $parts = false;
                }
                $schemes = $mode === 'http' ? ['http', 'https'] : ($mode === 'socks5' ? ['socks5', 'socks5h'] : ['http', 'https', 'socks5', 'socks5h']);
                if (!is_array($parts) || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], $schemes, true)
                    || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
                    || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
                    throw new ConfigException('Invalid proxy endpoint for ' . $provider . '.');
                }
                foreach (['user', 'pass'] as $field) {
                    $credential = rawurldecode($parts[$field] ?? '');
                    if (strlen($credential) > 255 || preg_match('/[\x00-\x1f\x7f]/', $credential) === 1) {
                        throw new ConfigException('Invalid proxy authentication for ' . $provider . '.');
                    }
                }
                if (isset($parts['port']) && $parts['port'] < 1) {
                    throw new ConfigException('Invalid proxy port for ' . $provider . '.');
                }
                $host = trim($parts['host'], '[]');
                $private = $host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) !== false
                    && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
                if ((isset($parts['user']) || isset($parts['pass'])) && $parts['scheme'] !== 'https' && !$private) {
                    throw new ConfigException('Proxy authentication requires TLS or a private/tunnel endpoint for ' . $provider . '.');
                }
                if ($mode === 'tunnel' && !$private) {
                    throw new ConfigException('Tunnel egress must use a private local endpoint for ' . $provider . '.');
                }
            }
            // Explicit empty proxy disables ambient HTTP(S)_PROXY for direct routes.
            $this->routes[$provider] = ['mode' => $mode, 'proxy' => $mode === 'direct' ? '' : $url];
        }
        foreach ($env->list('EGRESS_REQUIRED_PROVIDERS') as $provider) {
            if (!in_array($provider, [...self::PROVIDERS, 'telegram-reader'], true)) {
                throw new ConfigException('Unknown required egress provider.');
            }
        }
    }

    /** Secret-config fingerprint is keyed and never returned by diagnostics. */
    public function identity(string $provider): string
    {
        $route = $this->route($provider);
        return hash_hmac('sha256', $provider . ':' . $route['mode'] . ':' . $route['proxy'], $this->identityKey);
    }

    /** Reader proves its loaded route matches Core without sending the proxy credential. */
    public function readerIdentity(string $sharedSecret): string
    {
        $route = $this->route('telegram');
        return hash_hmac('sha256', 'telegram:' . $route['mode'] . ':' . $route['proxy'], $sharedSecret);
    }

    public function providerFor(string $url): string
    {
        return match (strtolower((string) parse_url($url, PHP_URL_HOST))) {
            'api.telegram.org' => 'telegram',
            'api.vk.com', 'oauth.vk.com' => 'vk',
            'platform-api.max.ru' => 'max',
            'api.openai.com' => 'openai',
            'api.tineye.com' => 'tineye',
            'api.replicate.com' => 'replicate',
            'api.anthropic.com' => 'anthropic',
            default => '',
        };
    }

    /** @return array{mode: string, proxy: string} */
    public function route(string $provider): array
    {
        if ($provider === 'telegram-reader') {
            $provider = 'telegram';
        }
        if ($provider === '') {
            return ['mode' => 'direct', 'proxy' => ''];
        }
        return $this->routes[$provider] ?? throw new ConfigException('Unknown egress provider.');
    }
}
