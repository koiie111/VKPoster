<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\Ledger;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * AI credits of a workspace: the monthly allowance of its plan (`usage_counters`, metric `ai_credits`, shared with the content
 * features; `null` limit = unlimited) plus credits bought as add-on packs (the ledger wallet `wallet:CRD:<workspace>`).
 * The monthly allowance is spent first, then the wallet. Credits are taken AFTER a successful answer (`spend`); before the call only
 * `canAfford` is asked, so a model error never costs anything.
 */
final class AiCredits
{
    public const METRIC = 'ai_credits';

    public function __construct(
        private readonly Connection $db,
        private readonly Entitlements $entitlements,
        private readonly Ledger $ledger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{used: int, limit: int|null, wallet: int, left: int|null} `left` is null when the plan is unlimited
     */
    public function summary(int $workspaceId, string $workspacePublicId): array
    {
        $limit = $this->entitlements->limit($workspaceId, self::METRIC);
        $used = (int) ($this->db->select('SELECT value FROM usage_counters WHERE workspace_id = ? AND metric = ? AND period_key = ?', [$workspaceId, self::METRIC, $this->period()])[0]['value'] ?? 0);
        $wallet = max(0, $this->ledger->wallet($workspacePublicId)['credits']);

        return ['used' => $used, 'limit' => $limit, 'wallet' => $wallet, 'left' => $limit === null ? null : max(0, $limit - $used) + $wallet];
    }

    public function canAfford(int $workspaceId, string $workspacePublicId, int $cost): bool
    {
        $left = $this->summary($workspaceId, $workspacePublicId)['left'];

        return $left === null || $left >= $cost;
    }

    /**
     * Take credits for one answered request: the monthly allowance first (one conditional UPDATE, so two requests cannot both take the
     * last credits), the rest from the wallet. `$ref` makes the wallet part idempotent.
     */
    public function spend(int $workspaceId, string $workspacePublicId, int $cost, string $ref): void
    {
        if ($cost <= 0) {
            return;
        }
        $limit = $this->entitlements->limit($workspaceId, self::METRIC);
        $now = DbTime::format($this->clock->now());
        $period = $this->period();
        $this->db->execute('INSERT IGNORE INTO usage_counters (workspace_id, metric, period_key, value, updated_at) VALUES (?, ?, ?, 0, ?)', [$workspaceId, self::METRIC, $period, $now]);
        if ($limit === null) {
            $this->db->execute('UPDATE usage_counters SET value = value + ?, updated_at = ? WHERE workspace_id = ? AND metric = ? AND period_key = ?', [$cost, $now, $workspaceId, self::METRIC, $period]);

            return;
        }
        $fromPlan = 0;
        for ($take = $cost; $take > 0; --$take) {
            if ($this->db->execute('UPDATE usage_counters SET value = value + ?, updated_at = ? WHERE workspace_id = ? AND metric = ? AND period_key = ? AND value + ? <= ?', [$take, $now, $workspaceId, self::METRIC, $period, $take, $limit]) === 1) {
                $fromPlan = $take;
                break;
            }
        }
        $rest = $cost - $fromPlan;
        if ($rest > 0) {
            $this->ledger->post('ai_spend', $ref, 'Расход ИИ-кредитов', 'CRD', [
                ['code' => 'wallet:CRD:' . $workspacePublicId, 'name' => 'Кошелёк пространства (CRD)', 'kind' => 'liability', 'amount' => $rest],
                ['code' => 'spent:CRD', 'name' => 'Потрачено ИИ-кредитов', 'kind' => 'income', 'amount' => -$rest],
            ]);
        }
    }

    /** Calendar month in UTC; the allowance renews on the 1st (same key as the other monthly counters). */
    private function period(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
    }
}
