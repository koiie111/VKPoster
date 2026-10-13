<?php

declare(strict_types=1);

namespace App\Domain\Content\Providers;

use App\Domain\Billing\Entitlements;
use App\Integrations\ContentProviders\ProviderException;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Workspace plan gate and idempotent allowance ledger for durable paid operations; Fake work is not billed. */
final class ProviderUsage
{
    /** @var array{workspace:int,operation:string,key:string}|null */
    private ?array $current = null;
    private const OPERATIONS = ['text', 'semantic', 'image_search', 'image_enhancement', 'video'];
    public function __construct(private readonly Connection $db, private readonly Entitlements $entitlements, private readonly Clock $clock)
    {
    }
    /** Scope all HTTP requests (upload/poll/retry included) to the originating workspace.
     * @phpstan-impure
     * @template T
     * @param \Closure():T $call
     * @return T */
    public function run(int $workspace, string $operation, string $key, string $provider, \Closure $call): mixed
    {
        if (!in_array($provider, ['openai', 'tineye', 'replicate_esrgan', 'replicate_seedance'], true)) {
            return $call();
        }
        $this->assertAllowed($workspace, $operation);
        $prior = $this->current;
        $this->current = ['workspace' => $workspace, 'operation' => $operation, 'key' => hash('sha256', $operation . ':' . $key)];
        try {
            return $call();
        } finally {
            $this->current = $prior;
        }
    }
    public function workspace(): int
    {
        return $this->current['workspace'] ?? throw new ProviderException('workspace_context_missing');
    }
    /** Called after Redis grants a lease and before HTTP dispatch. No allowance is used when a plan or limiter refuses. */
    public function consume(): void
    {
        $scope = $this->current ?? throw new ProviderException('workspace_context_missing');
        $this->db->transaction(function () use ($scope): void {
            $workspace = $scope['workspace'];
            $row = $this->db->select('SELECT timezone FROM workspaces WHERE id=? FOR UPDATE', [$workspace])[0] ?? throw new ProviderException('workspace_unavailable');
            $this->assertAllowed($workspace, $scope['operation']);
            $metric = 'content_' . $scope['operation'];
            $period = $this->clock->now()->setTimezone(new \DateTimeZone((string) $row['timezone']))->format('Y-m');
            $now = DbTime::format($this->clock->now());
            if ($this->db->select('SELECT id FROM content_provider_usage WHERE workspace_id=? AND operation_key=?', [$workspace, $scope['key']]) !== []) {
                return;
            }
            $limit = $this->entitlements->limit($workspace, $metric . '_per_month');
            $used = (int) ($this->db->select('SELECT value FROM usage_counters WHERE workspace_id=? AND metric=? AND period_key=? FOR UPDATE', [$workspace, $metric, $period])[0]['value'] ?? 0);
            if ($limit !== null && $used >= $limit) {
                throw new ProviderException('workspace_allowance_exhausted');
            }
            $this->db->execute('INSERT INTO content_provider_usage (workspace_id, operation_key, metric, period_key, created_at) VALUES (?, ?, ?, ?, ?)', [$workspace, $scope['key'], $metric, $period, $now]);
            $this->db->execute('INSERT INTO usage_counters (workspace_id, metric, period_key, value, updated_at) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE value=value+1, updated_at=VALUES(updated_at)', [$workspace, $metric, $period, $now]);
        });
    }
    private function assertAllowed(int $workspace, string $operation): void
    {
        if (!in_array($operation, self::OPERATIONS, true) || !$this->entitlements->hasFeature($workspace, 'content_' . $operation)) {
            throw new ProviderException('plan_feature_denied');
        }
    }
}
