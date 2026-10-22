<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use App\Kernel\Env;
use App\Support\Clock;

/** Transport observations only: no credentials/URLs, no remote probe from a web readiness request. */
final class EgressHealth
{
    /** @param \Redis|\Closure(): \Redis $redis Lazy so liveness never connects to Redis. */
    public function __construct(private readonly \Redis|\Closure $redis, private readonly Env $env, private readonly Clock $clock)
    {
    }

    public function record(string $provider, string $mode, bool $success): void
    {
        $this->observe($provider, $mode, $success ? 'ok' : 'fail');
    }

    private function observe(string $provider, string $mode, string $state): void
    {
        if (!in_array($provider, [...EgressRoutes::PROVIDERS, 'telegram-reader'], true)) {
            return;
        }
        try {
            $key = 'egress:' . $provider;
            $fields = ['route_id' => (new EgressRoutes($this->env))->identity($provider), 'state' => $state, 'mode' => $mode, 'at' => (string) $this->clock->now()->getTimestamp()];
            if ($state === 'ok') {
                $fields['last_success'] = $fields['at'];
            }
            $this->connection()->hMset($key, $fields);
            $this->connection()->expire($key, 900);
        } catch (\Throwable) {
            // Telemetry failure must not turn a successful paid/publication call into a retry.
        }
    }

    public function recordReader(string $state, string $mode, string $routeId): void
    {
        $routes = new EgressRoutes($this->env);
        $expected = $routes->route('telegram')['mode'];
        $secret = $this->env->string('SOURCES_READER_SECRET');
        $matches = strlen($secret) >= 32 && hash_equals($routes->readerIdentity($secret), $routeId);
        $this->observe('telegram-reader', $mode, $matches && $mode === $expected && in_array($state, ['ok', 'fail', 'unknown'], true) ? $state : 'fail');
    }

    /** @return array<string, array{state: string, mode: string, last_success: int|null}> */
    public function report(): array
    {
        $routes = new EgressRoutes($this->env);
        $report = [];
        foreach ([...EgressRoutes::PROVIDERS, 'telegram-reader'] as $provider) {
            try {
                $row = $this->connection()->hGetAll('egress:' . $provider);
            } catch (\Throwable) {
                $row = [];
            }
            $sameRoute = isset($row['route_id']) && hash_equals($routes->identity($provider), (string) $row['route_id']);
            $fresh = $sameRoute && isset($row['at']) && (int) $row['at'] >= $this->clock->now()->getTimestamp() - 300;
            $report[$provider] = ['state' => $fresh && in_array($row['state'] ?? '', ['ok', 'fail'], true) ? $row['state'] : 'unknown',
                'mode' => $routes->route($provider)['mode'], 'last_success' => $sameRoute && isset($row['last_success']) ? (int) $row['last_success'] : null];
        }
        return $report;
    }

    private function connection(): \Redis
    {
        return $this->redis instanceof \Redis ? $this->redis : ($this->redis)();
    }

    public function requiredReady(): bool
    {
        $report = $this->report();
        foreach ($this->env->list('EGRESS_REQUIRED_PROVIDERS') as $provider) {
            if (($report[$provider]['state'] ?? 'unknown') !== 'ok') {
                return false;
            }
        }
        return true;
    }
}
