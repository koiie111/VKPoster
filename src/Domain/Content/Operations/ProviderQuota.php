<?php

declare(strict_types=1);

namespace App\Domain\Content\Operations;

use App\Domain\Billing\Entitlements;
use App\Integrations\ContentProviders\ProviderException;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Closure;

/**
 * Per-workspace fence in front of every paid content provider call (OpenAI, TinEye, Replicate).
 *
 * `ProviderLimits` protects the whole service (one shared budget in Redis); this class makes the budget fair and ties it to the plan:
 * every call costs AI credits, taken from the monthly `ai_credits` allowance of the workspace's plan (`usage_counters`, `null` = unlimited),
 * and a workspace may start only a few calls per minute, so one workspace cannot use up the shared budget for the others.
 * Fake or disabled providers are free, so local runs and tests are not metered.
 */
final class ProviderQuota
{
    public const METRIC = 'ai_credits';

    /** Credits per call: text and semantic evaluation are cheap, a generated video is expensive. */
    private const COSTS = ['text' => 1, 'semantic' => 1, 'image_search' => 1, 'image_enhancement' => 2, 'video' => 10];

    public function __construct(
        private readonly Connection $db,
        private readonly Entitlements $entitlements,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly \Redis $redis,
    ) {
    }

    /**
     * Charges the call before it starts, runs it, and gives the credits back when the provider refused it before doing any work
     * (a retryable failure). An ambiguous failure keeps the charge: the provider may have done the paid work.
     *
     * @template T
     * @param Closure():T $call
     * @return T
     * @throws ProviderException `quota_exceeded` / `workspace_rate_limited` / `limits_unavailable`
     */
    public function run(int $workspaceId, string $operation, Closure $call): mixed
    {
        $cost = $this->charge($workspaceId, $operation);
        try {
            return $call();
        } catch (ProviderException $e) {
            if ($e->retryable && $cost > 0) {
                $this->refund($workspaceId, $cost);
            }
            throw $e;
        }
    }

    /**
     * @return int credits taken (0 when the provider is free)
     * @throws ProviderException
     */
    public function charge(int $workspaceId, string $operation): int
    {
        if (!$this->metered($operation)) {
            return 0;
        }
        $cost = self::COSTS[$operation] ?? throw new \InvalidArgumentException('Unknown content operation');
        $this->burst($workspaceId);
        $limit = $this->entitlements->limit($workspaceId, self::METRIC);
        $period = $this->period();
        $now = DbTime::format($this->clock->now());
        if ($limit !== null && $cost > $limit) {
            throw new ProviderException('quota_exceeded');
        }
        $this->db->execute('INSERT IGNORE INTO usage_counters (workspace_id, metric, period_key, value, updated_at) VALUES (?, ?, ?, 0, ?)', [$workspaceId, self::METRIC, $period, $now]);
        // One conditional UPDATE: two concurrent workers can never both pass the last credits.
        $taken = $limit === null
            ? $this->db->execute('UPDATE usage_counters SET value = value + ?, updated_at = ? WHERE workspace_id = ? AND metric = ? AND period_key = ?', [$cost, $now, $workspaceId, self::METRIC, $period])
            : $this->db->execute('UPDATE usage_counters SET value = value + ?, updated_at = ? WHERE workspace_id = ? AND metric = ? AND period_key = ? AND value + ? <= ?', [$cost, $now, $workspaceId, self::METRIC, $period, $cost, $limit]);
        if ($taken !== 1) {
            throw new ProviderException('quota_exceeded');
        }

        return $cost;
    }

    public function refund(int $workspaceId, int $credits): void
    {
        $this->db->execute('UPDATE usage_counters SET value = GREATEST(0, value - ?), updated_at = ? WHERE workspace_id = ? AND metric = ? AND period_key = ?', [$credits, DbTime::format($this->clock->now()), $workspaceId, self::METRIC, $this->period()]);
    }

    /** @return array{used: int, limit: int|null} for the billing page */
    public function usage(int $workspaceId): array
    {
        $row = $this->db->select('SELECT value FROM usage_counters WHERE workspace_id = ? AND metric = ? AND period_key = ?', [$workspaceId, self::METRIC, $this->period()])[0] ?? null;

        return ['used' => (int) ($row['value'] ?? 0), 'limit' => $this->entitlements->limit($workspaceId, self::METRIC)];
    }

    private function metered(string $operation): bool
    {
        $provider = $this->config->string('content_providers.' . $operation);

        return !in_array($provider, ['fake', 'disabled'], true);
    }

    /** The shared Redis budget is per service; this cap is per workspace. A Redis failure denies the paid call. */
    private function burst(int $workspaceId): void
    {
        $key = 'content-quota:' . $workspaceId . ':minute:' . intdiv($this->clock->now()->getTimestamp(), 60);
        try {
            $n = $this->redis->eval("local n=redis.call('INCR',KEYS[1]); redis.call('EXPIRE',KEYS[1],120); return n", [$key], 1);
        } catch (\Throwable) {
            throw new ProviderException('limits_unavailable', true);
        }
        if ((int) $n > $this->config->int('content_operations.workspace_per_minute', 5)) {
            throw new ProviderException('workspace_rate_limited', true);
        }
    }

    /** Calendar month in UTC; the monthly allowance renews on the 1st (the same key format as other monthly counters, `2026-10`). */
    private function period(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
    }
}
