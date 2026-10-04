<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Media\MediaPresenter;
use App\Support\Money;

/**
 * Turns plans into the rows of the comparison table and the short lists on the plan cards, in the customer's words.
 * Pure formatting: it reads limits and features from `Plan`, so a changed price list shows up without touching this class.
 */
final class PlanPresenter
{
    /**
     * Rows of the comparison table: label and one cell per plan code. A cell is text; `✓` and `—` mark a feature that is in or out.
     *
     * @param list<Plan> $plans
     * @return list<array{label: string, cells: array<string, string>}>
     */
    public static function rows(array $plans, string $currency = 'RUB'): array
    {
        $rows = [];
        $add = static function (string $label, callable $cell) use (&$rows, $plans): void {
            $cells = [];
            foreach ($plans as $plan) {
                $cells[$plan->code] = $cell($plan);
            }
            $rows[] = ['label' => $label, 'cells' => $cells];
        };
        $yes = static fn (Plan $p, string $feature): string => $p->hasFeature($feature) ? '✓' : '—';

        $add('Цена в месяц', static fn (Plan $p): string => $p->priceFor(BillingPeriod::Month, $currency) === null ? '0 ₽' : Money::format($p->priceFor(BillingPeriod::Month, $currency), $currency));
        $add('Цена в год', static fn (Plan $p): string => $p->priceFor(BillingPeriod::Year, $currency) === null ? '—' : Money::format($p->priceFor(BillingPeriod::Year, $currency), $currency));
        $add('Каналы', static fn (Plan $p): string => self::count($p->limit('channels')));
        $add('Постов в месяц', static fn (Plan $p): string => self::count($p->limit('posts_per_month')));
        $add('Пространства', static fn (Plan $p): string => self::count($p->limit('workspaces')));
        $add('Люди в команде', static fn (Plan $p): string => self::count($p->limit('members')));
        $add('Медиатека', static fn (Plan $p): string => $p->limit('storage_bytes') === null ? 'без ограничений' : MediaPresenter::size($p->limit('storage_bytes')));
        $add('Слоты, повторы, триггеры', static fn (Plan $p): string => $yes($p, 'slots'));
        $add('Кросспостинг и RSS', static function (Plan $p): string {
            $rules = $p->limit('crosspost_rules');

            return !$p->hasFeature('crosspost') || $rules === 0 ? '—' : ($rules === null ? 'без ограничений' : $rules . ' ' . Entitlements::upTo($rules, 'правило', 'правила'));
        });
        $add('Аналитика', static function (Plan $p): string {
            $days = (int) $p->limit('analytics_days');

            return ($days >= 365 ? 'за год' : 'за ' . $days . ' дней') . ($p->hasFeature('analytics_export') ? ' и выгрузка' : '');
        });
        $add('Согласование и гостевые ссылки', static fn (Plan $p): string => $yes($p, 'approvals'));
        $add('ИИ-кредиты в месяц', static fn (Plan $p): string => self::count($p->limit('ai_credits')));
        $add('API и вебхуки', static fn (Plan $p): string => $yes($p, 'api'));
        $add('Отчёты для клиентов и свой бренд', static fn (Plan $p): string => $yes($p, 'white_label'));

        return $rows;
    }

    /**
     * The few lines shown on a plan card.
     *
     * @return list<string>
     */
    public static function highlights(Plan $plan): array
    {
        $lines = [self::count($plan->limit('channels')) . ' ' . self::noun($plan->limit('channels'), 'канал', 'канала', 'каналов')];
        $posts = $plan->limit('posts_per_month');
        $lines[] = $posts === null ? 'Посты без ограничений' : $posts . ' ' . self::noun($posts, 'пост', 'поста', 'постов') . ' в месяц';
        $members = $plan->limit('members');
        $lines[] = $members === null ? 'Команда без ограничений' : 'В команде: до ' . $members;
        $lines[] = 'Медиатека: ' . ($plan->limit('storage_bytes') === null ? 'без ограничений' : MediaPresenter::size($plan->limit('storage_bytes')));
        if ($plan->hasFeature('slots')) {
            $lines[] = 'Слоты, повторы, триггеры';
        }
        if ($plan->hasFeature('approvals')) {
            $lines[] = 'Согласование и гостевые ссылки';
        }
        if ($plan->hasFeature('api')) {
            $lines[] = 'API и вебхуки';
        }
        if ($plan->hasFeature('white_label')) {
            $lines[] = 'Отчёты для клиентов и свой бренд';
        }

        return $lines;
    }

    private static function count(?int $value): string
    {
        return $value === null ? 'без ограничений' : (string) $value;
    }

    /** 1 канал, 2 канала, 5 каналов. */
    private static function noun(?int $n, string $one, string $few, string $many): string
    {
        $n = abs((int) $n);
        $last = $n % 10;
        $tail = $n % 100;
        if ($tail > 10 && $tail < 20) {
            return $many;
        }

        return $last === 1 ? $one : ($last >= 2 && $last <= 4 ? $few : $many);
    }
}
