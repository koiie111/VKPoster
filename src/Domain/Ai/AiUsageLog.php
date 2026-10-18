<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * One row per assistant request: who, which action, which provider and model, how many tokens and credits, how it ended.
 * Never the text of a post, a brief or an answer (the privacy rule of the stage).
 */
final class AiUsageLog
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function record(int $workspaceId, ?int $userId, AiAction $action, string $provider, string $model, bool $byok, string $status, int $credits, int $tokensIn, int $tokensOut, int $durationMs): int
    {
        $this->db->execute(
            'INSERT INTO ai_usage (workspace_id, user_id, action, provider, model, byok, status, credits, tokens_in, tokens_out, duration_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $userId, $action->value, mb_substr($provider, 0, 40), mb_substr($model, 0, 120), $byok ? 1 : 0, mb_substr($status, 0, 24), $credits, $tokensIn, $tokensOut, $durationMs, DbTime::format($this->clock->now())],
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Totals for the admin area over the last `$days` days.
     *
     * @return list<array{provider: string, model: string, requests: int, ok: int, credits: int, tokens_in: int, tokens_out: int}>
     */
    public function totals(int $days = 30): array
    {
        $since = DbTime::format($this->clock->now()->modify('-' . max(1, $days) . ' days'));
        $rows = $this->db->select(
            "SELECT provider, model, COUNT(*) AS requests, SUM(status = 'ok') AS ok, SUM(credits) AS credits, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out FROM ai_usage WHERE created_at >= ? GROUP BY provider, model ORDER BY requests DESC LIMIT 50",
            [$since],
        );

        return array_map(static fn (array $r): array => [
            'provider' => (string) $r['provider'], 'model' => (string) $r['model'], 'requests' => (int) $r['requests'], 'ok' => (int) $r['ok'],
            'credits' => (int) $r['credits'], 'tokens_in' => (int) $r['tokens_in'], 'tokens_out' => (int) $r['tokens_out'],
        ], $rows);
    }
}
