<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Changes of the price list made by the owner in the admin area: name, visibility, prices (rubles, dollars, euros or stars, per month and per year),
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

    /** Currencies a plan can be priced in; the checkout offers those that a switched-on payment provider takes. */
    public const CURRENCIES = ['RUB' => 'рубли', 'USD' => 'доллары', 'EUR' => 'евро', 'XTR' => 'звёзды Telegram'];

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
        // Prices by currency and period, in the unit the form shows (rubles, dollars, euros, whole stars); stored as ×100 like all money.
        $prices = [];
        foreach (self::CURRENCIES as $currency => $currencyLabel) {
            foreach (['month' => 'в месяц', 'year' => 'в год'] as $period => $label) {
                $field = 'price_' . $period . ($currency === 'RUB' ? '' : '_' . strtolower($currency));
                $raw = trim(str_replace([' ', "\u{00A0}"], '', (string) ($input[$field] ?? '')));
                if ($plan->isFree() || $raw === '') {
                    continue;
                }
                $pattern = $currency === 'XTR' ? '/^\d{1,5}$/' : '/^\d{1,7}([.,]\d{1,2})?$/';
                if (preg_match($pattern, $raw) !== 1 || (float) str_replace(',', '.', $raw) <= 0) {
                    $errors[] = 'Цена ' . $label . ' (' . $currencyLabel . ') должна быть положительным ' . ($currency === 'XTR' ? 'целым числом звёзд.' : 'числом.');
                    continue;
                }
                $prices[$currency][$period] = (int) round((float) str_replace(',', '.', $raw) * 100);
            }
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
            foreach (array_keys(self::CURRENCIES) as $currency) {
                foreach (['month', 'year'] as $period) {
                    $this->db->execute('DELETE FROM plan_prices WHERE plan_id = ? AND period = ? AND currency = ?', [$plan->id, $period, $currency]);
                    if (isset($prices[$currency][$period])) {
                        $this->db->table('plan_prices')->insert(['plan_id' => $plan->id, 'period' => $period, 'currency' => $currency, 'amount' => $prices[$currency][$period]]);
                    }
                }
            }
        });
        // The history of a price list: what it was and what it became, in the words of the plan form (money in kopecks, limits as typed).
        $this->audit->record('admin.plan_updated', $actorId, 'plan', $plan->code, [
            'before' => json_encode(['name' => $plan->name, 'month' => $plan->priceFor(BillingPeriod::Month), 'year' => $plan->priceFor(BillingPeriod::Year), 'other_prices' => $plan->prices, 'public' => $plan->isPublic, 'limits' => $plan->limits, 'features' => $plan->features], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode(['name' => $name, 'month' => $prices['RUB']['month'] ?? null, 'year' => $prices['RUB']['year'] ?? null, 'other_prices' => $prices, 'public' => ($input['is_public'] ?? '') === '1' || $plan->isFree(), 'limits' => $limits, 'features' => $features], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return [];
    }
}
