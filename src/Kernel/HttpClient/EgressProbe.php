<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use App\Kernel\Config;

/** Opt-in background GET probes; never sends credentials or runs from HTTP readiness. */
final class EgressProbe
{
    private const ENDPOINTS = ['telegram' => 'https://api.telegram.org/', 'vk' => 'https://api.vk.com/',
        'max' => 'https://platform-api.max.ru/', 'openai' => 'https://api.openai.com/',
        'tineye' => 'https://api.tineye.com/', 'replicate' => 'https://api.replicate.com/', 'anthropic' => 'https://api.anthropic.com/'];

    public function __construct(private readonly HttpClientInterface $http, private readonly Config $config, private readonly EgressRoutes $routes, private readonly EgressHealth $health)
    {
    }

    public function tick(): void
    {
        if (!$this->config->env()->bool('EGRESS_PROBE_ENABLED', false)) {
            return;
        }
        $required = $this->config->env()->list('EGRESS_REQUIRED_PROVIDERS');
        foreach (self::ENDPOINTS as $provider => $url) {
            $route = $this->routes->route($provider);
            if ($route['mode'] === 'direct' && !in_array($provider, $required, true)) {
                continue;
            }
            try {
                $response = $this->http->request('GET', $url, ['egress_provider' => $provider,
                    'timeout' => 3, 'connect_timeout' => 1, 'follow_redirects' => false]);
                $this->health->record($provider, $route['mode'], $response->getStatusCode() < 500);
            } catch (\Throwable) {
                $this->health->record($provider, $route['mode'], false);
            }
        }
    }
}
