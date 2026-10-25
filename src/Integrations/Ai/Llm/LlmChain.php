<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * The order in which the assistant tries language models, set by the owner: for example the free models of a gateway first (each
 * with its own daily request cap), a paid one last. A step is a provider from `LlmSettings` (key and address live there), an optional
 * model that overrides the provider's, and a cap of requests per UTC day. Counters and cool-downs live in Redis, so they follow every
 * worker; a Redis failure makes a step unavailable rather than letting a paid model be hammered.
 */
final class LlmChain
{
    public const MAX_STEPS = 20;

    private const COUNTER_TTL = 172800;

    /** Seconds a step is skipped after a failure; `refused` is about the text, not the model, so it has none. */
    private const COOLDOWN = ['rate_limited' => 900, 'credentials' => 600, 'not_configured' => 600, 'forbidden_url' => 600, 'bad_request' => 600, 'unavailable' => 60, 'timeout' => 60, 'bad_response' => 60];

    public function __construct(
        private readonly Connection $db,
        private readonly LlmSettings $settings,
        private readonly \Redis $redis,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array{id: int, position: int, provider: string, model: string, label: string, daily_requests: int, reset_offset_minutes: int, cooldown_seconds: int, charge_credits: bool, actions: list<string>, enabled: bool}>
     */
    public function all(): array
    {
        $rows = $this->db->select('SELECT id, position, provider_code, model, label, daily_requests, reset_offset_minutes, cooldown_seconds, charge_credits, actions, enabled FROM ai_chain_steps ORDER BY position, id');

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'position' => (int) $r['position'], 'provider' => (string) $r['provider_code'], 'model' => (string) $r['model'],
            'label' => (string) $r['label'], 'daily_requests' => (int) $r['daily_requests'],
            'reset_offset_minutes' => (int) $r['reset_offset_minutes'], 'cooldown_seconds' => (int) $r['cooldown_seconds'], 'charge_credits' => (int) $r['charge_credits'] === 1,
            'actions' => array_values(array_filter(explode(',', (string) $r['actions']), static fn (string $a): bool => $a !== '')), 'enabled' => (int) $r['enabled'] === 1,
        ], $rows);
    }

    /**
     * Steps that may answer now, in order: switched on, serving this action (`$action`; empty = any) and with a provider that has a key. Caps are checked by `acquire`.
     *
     * @return list<array{id: int, position: int, provider: string, model: string, label: string, daily_requests: int, reset_offset_minutes: int, cooldown_seconds: int, charge_credits: bool, actions: list<string>, enabled: bool}>
     */
    public function usable(string $action = ''): array
    {
        $usable = $this->settings->usable();

        $steps = [];
        foreach ($this->all() as $step) {
            if ($step['enabled'] && isset($usable[$step['provider']]) && ($action === '' || $step['actions'] === [] || in_array($action, $step['actions'], true))) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * Takes one request of the step's day, unless it cools down after a failure or its cap is spent.
     *
     * @param array{id: int, daily_requests: int, reset_offset_minutes: int} $step
     */
    public function acquire(array $step): bool
    {
        try {
            $n = $this->redis->eval(
                "if redis.call('EXISTS',KEYS[2])==1 then return -1 end local n=redis.call('INCR',KEYS[1]) redis.call('EXPIRE',KEYS[1],tonumber(ARGV[2])) if tonumber(ARGV[1])>0 and n>tonumber(ARGV[1]) then redis.call('DECR',KEYS[1]) return 0 end return n",
                [$this->usedKey($step['id'], $step['reset_offset_minutes']), $this->coolKey($step['id']), (string) $step['daily_requests'], (string) self::COUNTER_TTL],
                2,
            );
        } catch (\Throwable) {
            return false;
        }

        return (int) $n > 0;
    }

    /**
     * A request failed: park the step for a while and give back the request unless the provider counted it (it said "too many").
     *
     * @param array{id: int, reset_offset_minutes: int, cooldown_seconds: int} $step
     */
    public function failed(array $step, string $reason): void
    {
        try {
            $pause = $reason === 'refused' ? 0 : ($step['cooldown_seconds'] > 0 ? $step['cooldown_seconds'] : (self::COOLDOWN[$reason] ?? 0));
            if ($pause > 0) {
                $this->redis->setex($this->coolKey($step['id']), $pause, '1');
            }
            if ($reason !== 'rate_limited' && $reason !== 'refused') {
                $this->redis->eval("local n=redis.call('GET',KEYS[1]) if n and tonumber(n)>0 then redis.call('DECR',KEYS[1]) end return 1", [$this->usedKey($step['id'], $step['reset_offset_minutes'])], 1);
            }
        } catch (\Throwable) {
            // The counters are an aid; the call has already failed for the user.
        }
    }

    public function usedToday(int $id, int $offsetMinutes = 0): int
    {
        try {
            return max(0, (int) $this->redis->get($this->usedKey($id, $offsetMinutes)));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function cooling(int $id): bool
    {
        try {
            return (bool) $this->redis->exists($this->coolKey($id));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input `provider`, `model`, `label`, `daily_requests`, `reset_offset_minutes`, `cooldown_seconds`, `charge_credits`, `actions` (list), `position`, `enabled`
     * @return list<string> problems to show (nothing saved when there are some)
     */
    public function save(?int $id, array $input, ?int $actorId): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $provider = $text('provider');
        $model = $text('model');
        $label = $text('label');
        $limit = $text('daily_requests');
        $position = $text('position');
        $offset = $text('reset_offset_minutes');
        $cooldown = $text('cooldown_seconds');
        $actions = is_array($input['actions'] ?? null) ? array_values(array_unique(array_filter($input['actions'], 'is_string'))) : [];
        $errors = [];
        if (!LlmCatalog::isKnown($provider) && !isset($this->settings->all()[$provider])) {
            $errors[] = 'Выберите провайдера.';
        }
        if ($model !== '' && preg_match('/^[\w.:\/@\- ]{1,120}$/u', $model) !== 1) {
            $errors[] = 'Название модели заполнено неверно.';
        }
        if (mb_strlen($label) > 80) {
            $errors[] = 'Название шага не длиннее 80 знаков.';
        }
        if ($limit !== '' && (preg_match('/^\d{1,7}$/D', $limit) !== 1)) {
            $errors[] = 'Лимит запросов в сутки: целое число, 0 = без лимита.';
        }
        if ($position !== '' && preg_match('/^\d{1,3}$/D', $position) !== 1) {
            $errors[] = 'Порядок: число от 1 до 999.';
        }
        if ($offset !== '' && (preg_match('/^-?\d{1,4}$/D', $offset) !== 1 || abs((int) $offset) > 840)) {
            $errors[] = 'Сдвиг начала суток от UTC: минуты от -840 до 840 (Москва = 180).';
        }
        if ($cooldown !== '' && (preg_match('/^\d{1,5}$/D', $cooldown) !== 1 || (int) $cooldown > 86400)) {
            $errors[] = 'Пауза после ошибки: секунды от 0 до 86400 (0 = по причине ошибки).';
        }
        if (array_diff($actions, array_map(static fn (\App\Domain\Ai\AiAction $a): string => $a->value, \App\Domain\Ai\AiAction::cases())) !== []) {
            $errors[] = 'Неизвестное действие ассистента.';
        }
        if ($id === null && count($this->all()) >= self::MAX_STEPS) {
            $errors[] = 'В цепочке не больше ' . self::MAX_STEPS . ' шагов.';
        }
        if ($id !== null && $this->db->select('SELECT id FROM ai_chain_steps WHERE id = ?', [$id]) === []) {
            $errors[] = 'Такого шага нет.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $enabled = ($input['enabled'] ?? '') === '1' ? 1 : 0;
        $charge = ($input['charge_credits'] ?? '') === '1' ? 1 : 0;
        $actionList = implode(',', $actions);
        $now = DbTime::format($this->clock->now());
        $label = $label !== '' ? $label : ($model !== '' ? $model : $provider);
        if ($id === null) {
            $next = $position !== '' ? (int) $position : (int) ($this->db->select('SELECT COALESCE(MAX(position), 0) + 1 AS n FROM ai_chain_steps')[0]['n'] ?? 1);
            $this->db->execute(
                'INSERT INTO ai_chain_steps (position, provider_code, model, label, daily_requests, reset_offset_minutes, cooldown_seconds, charge_credits, actions, enabled, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [min($next, 999), $provider, $model, $label, (int) $limit, (int) $offset, (int) $cooldown, $charge, $actionList, $enabled, $actorId, $now],
            );
        } else {
            $this->db->execute(
                'UPDATE ai_chain_steps SET position = COALESCE(?, position), provider_code = ?, model = ?, label = ?, daily_requests = ?, reset_offset_minutes = ?, cooldown_seconds = ?, charge_credits = ?, actions = ?, enabled = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$position !== '' ? (int) $position : null, $provider, $model, $label, (int) $limit, (int) $offset, (int) $cooldown, $charge, $actionList, $enabled, $actorId, $now, $id],
            );
        }

        return [];
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM ai_chain_steps WHERE id = ?', [$id]);
    }

    private function usedKey(int $id, int $offsetMinutes): string
    {
        return 'ai-chain:used:' . $id . ':' . $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(($offsetMinutes >= 0 ? '+' : '-') . abs($offsetMinutes) . ' minutes')->format('Ymd');
    }

    private function coolKey(int $id): string
    {
        return 'ai-chain:cool:' . $id;
    }
}
