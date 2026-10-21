<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Domain\Admin\SystemStatus;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use App\Support\DbTime;

/** Uses existing system/queue read models; opted-in alerts contain stable incident codes only. */
final class OperationalMonitor
{
    public function __construct(
        private readonly SystemStatus $status,
        private readonly Connection $db,
        private readonly \Redis $redis,
        private readonly HttpClientInterface $http,
        private readonly Config $config,
        private readonly Clock $clock
    ) {
    }

    /** @return array{queue: array<string, mixed>, incidents: list<string>, payment_errors: int, platform_errors: int} */
    public function report(): array
    {
        $queue = $this->status->queue();
        $incidents = [];
        foreach ($this->status->checks() as $index => $check) {
            if ($check['state'] === 'bad') {
                $incidents[] = 'health-' . $index;
            }
        }
        if (($queue['oldest_seconds'] ?? 0) > 120 || $queue['failed'] > 0 || $queue['waiting'] > 1000) {
            $incidents[] = 'queue-backlog';
        }
        $since = DbTime::format($this->clock->now()->modify('-15 minutes'));
        $platform = (int) ($this->db->select("SELECT COUNT(*) AS n FROM publications WHERE status IN ('failed','unknown') AND updated_at >= ?", [$since])[0]['n'] ?? 0);
        $payments = (int) ($this->db->select("SELECT COUNT(*) AS n FROM payments WHERE status = 'failed' AND updated_at >= ?", [$since])[0]['n'] ?? 0);
        if ($platform > 0) {
            $incidents[] = 'platform-errors';
        }
        if ($payments > 0) {
            $incidents[] = 'payment-errors';
        }

        foreach ((new \App\Kernel\HttpClient\EgressHealth($this->redis, $this->config->env(), $this->clock))->report() as $provider => $egress) {
            if ($egress['state'] === 'fail') {
                $incidents[] = 'egress-' . $provider;
            }
        }
        return ['egress' => (new \App\Kernel\HttpClient\EgressHealth($this->redis, $this->config->env(), $this->clock))->report(), 'queue' => $queue, 'incidents' => $incidents, 'platform_errors' => $platform, 'payment_errors' => $payments];
    }

    public function tick(): void
    {
        $env = $this->config->env();
        if (!$env->bool('OPS_MONITOR_ENABLED') || !$env->has('OPS_TELEGRAM_BOT_TOKEN') || !$env->has('OPS_TELEGRAM_CHAT_ID')) {
            return;
        }
        foreach ($this->report()['incidents'] as $incident) {
            try {
                // Claim before dispatch. A transport failure cannot cause an alert storm.
                if (!$this->redis->set('ops:alert:' . $incident, '1', ['nx', 'ex' => 900])) {
                    continue;
                }
                $this->http->request('POST', 'https://api.telegram.org/bot' . $env->required('OPS_TELEGRAM_BOT_TOKEN') . '/sendMessage', [
                    'timeout' => 3, 'connect_timeout' => 1, 'follow_redirects' => false,
                    'json' => ['chat_id' => $env->required('OPS_TELEGRAM_CHAT_ID'), 'text' => 'ezposter operational alert: ' . $incident],
                ]);
            } catch (\Throwable) {
                // Fail closed on Redis failures; external uptime probes cover complete app/scheduler outages.
            }
        }
    }
}
