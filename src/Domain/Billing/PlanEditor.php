<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Changes of the price list made by the owner in the admin area: name, visibility, prices (rubles per month and per year),
 * limits and the features a plan switches on. Takes effect for new payments at once; what a customer has already paid is not touched
 * (a subscription keeps its own `price_amount`).
 */
final class PlanEditor
{
    /** Limit keys in the order the form shows them, with the label of each. `storage_bytes` is edited in megabytes. */
    public const LIMITS = [
        'channels' => 'Каналы',
        'posts_per_month' => 'Постов в месяц',
        'workspaces' => 'Пространства',
        'members' => 'Люди в команде',
        'storage_bytes' => 'Медиатека, МБ',
        'crosspost_rules' => 'Правил кросспостинга',
        'analytics_days' => 'Аналитика, дней',
        'ai_credits' => 'ИИ-кредитов в месяц',
    ];

    /** Features a plan can switch on. */
    public const FEATURES = [
        'slots' => 'Слоты, повторы, триггеры',
        'crosspost' => 'Кросспостинг и RSS',
        'approvals' => 'Согласование',
        'guest_links' => 'Гостевые ссылки',
        'api' => 'API и вебхуки',
        'analytics_export' => 'Выгрузка аналитики',
        'client_reports' => 'Отчёты для клиентов',
        'white_label' => 'Свой бренд',
    ];

    private const MAX_LIMIT = 1_000_000_000;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input `name`, `is_public`, `price_month`, `price_year` (rubles, empty = no such price), `limit_<key>` (empty = unlimited), `feature_<key>`
     * @return list<string> problems in the user's words; empty when the plan was saved
     */
    public function update(Plan $plan, array $input, ?int $actorId): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            $errors[] = 'Название должно быть от 2 до 50 символов.';
        }
        $prices = [];
        foreach (['month' => 'в месяц', 'year' => 'в год'] as $period => $label) {
            $raw = trim(str_replace([' ', "\u{00A0}"], '', (string) ($input['price_' . $period] ?? '')));
            if ($plan->isFree() || $raw === '') {
                continue;
            }
            if (preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $raw) !== 1 || (float) str_replace(',', '.', $raw) <= 0) {
                $errors[] = 'Цена ' . $label . ' должна быть положительным числом в рублях.';
                continue;
            }
            $prices[$period] = (int) round((float) str_replace(',', '.', $raw) * 100);
        }
        if (!$plan->isFree() && $prices === []) {
            $errors[] = 'Укажите хотя бы одну цену: платный тариф без цены нельзя купить.';
        }
        $limits = [];
        foreach (self::LIMITS as $key => $label) {
            $raw = trim((string) ($input['limit_' . $key] ?? ''));
            if ($raw === '') {
                $limits[$key] = null;
                continue;
            }
            if (preg_match('/^\d{1,10}$/', $raw) !== 1 || (int) $raw > self::MAX_LIMIT) {
                $errors[] = 'Лимит «' . $label . '» должен быть целым числом без знака (пусто = без ограничений).';
                continue;
            }
            $limits[$key] = $key === 'storage_bytes' ? (int) $raw * 1024 * 1024 : (int) $raw;
        }
        if ($errors !== []) {
            return $errors;
        }
        $features = [];
        foreach (self::FEATURES as $key => $label) {
            if (($input['feature_' . $key] ?? '') === '1') {
                $features[] = $key;
            }
        }
        $this->db->transaction(function () use ($plan, $name, $input, $limits, $features, $prices): void {
            $this->db->table('plans')->where('id', '=', $plan->id)->update([
                'name' => $name,
                'is_public' => ($input['is_public'] ?? '') === '1' || $plan->isFree() ? 1 : 0,
                'limits_json' => json_encode($limits, JSON_THROW_ON_ERROR),
                'features_json' => json_encode($features, JSON_THROW_ON_ERROR),
                'updated_at' => DbTime::format($this->clock->now()),
            ]);
            foreach (['month', 'year'] as $period) {
                $this->db->execute('DELETE FROM plan_prices WHERE plan_id = ? AND period = ? AND currency = ?', [$plan->id, $period, 'RUB']);
                if (isset($prices[$period])) {
                    $this->db->table('plan_prices')->insert(['plan_id' => $plan->id, 'period' => $period, 'currency' => 'RUB', 'amount' => $prices[$period]]);
                }
            }
        });
        $this->audit->record('admin.plan_updated', $actorId, 'plan', $plan->code, ['month' => $prices['month'] ?? null, 'year' => $prices['year'] ?? null]);

        return [];
    }
}
