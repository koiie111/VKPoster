<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminStats;
use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Analytics\MetricsReader;
use App\Domain\Analytics\ReportFilters;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Settings\Settings;
use App\Domain\Status\PlatformStatus;
use App\Domain\Admin\StaffAccess;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin: the main dashboard. Business numbers with the comparison to the previous period, charts, the funnel and retention cohorts,
 * narrowed by period, plan, network, source and currency; above them what needs attention right now (queues, broken channels, outages).
 * Money is shown only to roles that may see finance; the rest of the numbers need the statistics permission.
 */
final class DashboardController
{
    private const PERIODS = ['today' => 'Сегодня', '7' => '7 дней', '30' => '30 дней', '90' => '90 дней', '365' => 'Год', 'custom' => 'Свой период'];

    public function __construct(
        private readonly View $view,
        private readonly MetricsReader $reader,
        private readonly MetricsAggregator $aggregator,
        private readonly AdminStats $stats,
        private readonly StaffAccess $staff,
        private readonly LegalDocuments $legal,
        private readonly PlatformStatus $status,
        private readonly Settings $settings,
        private readonly FormFlash $flash,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $money = $this->staff->can($user, 'finance.view');
        $numbers = $this->staff->can($user, 'stats.view');

        $unfilled = [];
        foreach ($this->legal->all() as $document) {
            if ($document->isDraft()) {
                $unfilled[] = ['title' => $document->title, 'count' => $document->placeholders(), 'slug' => $document->slug];
            }
        }
        $data = [
            'accounts' => $this->stats->accounts(),
            'publications' => $this->stats->publications(),
            'queues' => $this->stats->queues(),
            'channels' => $this->stats->channels(),
            'problems' => $this->status->problems(),
            'unfilled_documents' => $unfilled,
            'show_money' => $money,
            'show_numbers' => $numbers,
            'periods' => self::PERIODS,
        ];
        if (!$numbers && !$money) {
            return $this->view->response('admin/overview.twig', $data);
        }

        $filters = $this->filters($request);
        $choices = $this->reader->choices();
        $currencies = $this->reader->currencies();
        $kpis = $this->reader->kpis($filters);
        $series = $this->reader->series($filters);
        $refreshed = $this->settings->get('metrics.refreshed_at');

        return $this->view->response('admin/overview.twig', $data + [
            'filters' => [
                'period' => $this->period($request),
                'from' => $filters->from->format('Y-m-d'),
                'to' => $filters->to->format('Y-m-d'),
                'plan' => $filters->plan,
                'source' => $filters->source,
                'platform' => $filters->platform,
                'currency' => $filters->currency,
            ],
            'plan_options' => ['' => 'Все тарифы'] + array_combine($choices['plans'], $choices['plans']),
            'source_options' => ['' => 'Все источники'] + array_combine($choices['sources'], $choices['sources']),
            'platform_options' => ['' => 'Все сети'] + array_combine($choices['platforms'], $choices['platforms']),
            'currency_options' => array_combine($currencies, $currencies),
            'cards' => $this->cards($kpis['current'], $kpis['previous'], $filters->currency, $money, $numbers),
            'charts' => $this->charts($series, $filters->currency, $money, $numbers),
            'plans_mix' => $series['plans'],
            'funnel' => $numbers ? $this->funnel($filters) : [],
            'cohorts' => $numbers ? $this->reader->cohorts(8) : [],
            'refreshed_at' => is_int($refreshed) ? (new DateTimeImmutable('@' . $refreshed)) : null,
            'has_data' => $series['labels'] !== [] && ($series['mrr'] !== [] || $series['signups'] !== [] || $series['revenue'] !== []),
            'previous_label' => $filters->previous()->from->format('d.m.Y') . ' – ' . $filters->previous()->to->format('d.m.Y'),
        ]);
    }

    /**
     * Recount the last days now instead of waiting for the hourly job.
     */
    public function refresh(Request $request): Response
    {
        $written = $this->aggregator->recent(3);
        $this->flash->toast('Цифры пересчитаны за последние дни (строк: ' . $written . ').');
        $back = $request->input('back');

        return Response::redirect(is_string($back) && str_starts_with($back, '/admin') && Response::isRelativeUrl($back) ? $back : '/admin');
    }

    private function period(Request $request): string
    {
        return AdminInput::choice($request, 'period', array_map('strval', array_keys(self::PERIODS)), '30');
    }

    private function filters(Request $request): ReportFilters
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $period = $this->period($request);
        $from = $today->modify('-29 days');
        $to = $today;
        if ($period === 'today') {
            $from = $today;
        } elseif (ctype_digit($period)) {
            $from = $today->modify('-' . ((int) $period - 1) . ' days');
        } else {
            $zone = 'UTC';
            $custom = AdminInput::date($request, 'from', $zone);
            $end = AdminInput::date($request, 'to', $zone);
            if ($custom !== null && $end !== null && $custom <= $end && $custom->diff($end)->days < 800) {
                [$from, $to] = [$custom, $end];
            }
        }
        $choices = $this->reader->choices();
        $currencies = $this->reader->currencies();
        $plan = AdminInput::text($request, 'plan', 32);
        $source = AdminInput::text($request, 'source', 60);
        $platform = AdminInput::text($request, 'platform', 16);
        $currency = AdminInput::text($request, 'currency', 3);

        return new ReportFilters(
            $from,
            $to,
            in_array($plan, $choices['plans'], true) ? $plan : '',
            in_array($platform, $choices['platforms'], true) ? $platform : '',
            in_array($source, $choices['sources'], true) ? $source : '',
            in_array($currency, $currencies, true) ? $currency : $currencies[0],
        );
    }

    /**
     * @param array<string, int|float|null> $now
     * @param array<string, int|float|null> $before
     * @return list<array{group: string, items: list<array<string, mixed>>}>
     */
    private function cards(array $now, array $before, string $currency, bool $money, bool $numbers): array
    {
        $m = static fn (int|float|null $v): string => $v === null ? '—' : Money::format((int) $v, $currency);
        $n = static fn (int|float|null $v): string => $v === null ? '—' : number_format((float) $v, is_float($v) && floor($v) !== $v ? 1 : 0, ',', "\u{00A0}");
        $p = static fn (int|float|null $v): string => $v === null ? '—' : number_format((float) $v, 1, ',', "\u{00A0}") . '%';
        $delta = static function (int|float|null $a, int|float|null $b): ?float {
            return $a === null || $b === null || (float) $b === 0.0 ? null : round(((float) $a - (float) $b) / abs((float) $b) * 100, 1);
        };
        $card = static fn (string $label, string $value, ?float $delta, ?string $hint = null, bool $goodUp = true): array => ['label' => $label, 'value' => $value, 'delta' => $delta, 'hint' => $hint, 'good_up' => $goodUp];
        $groups = [];
        if ($money) {
            $groups[] = ['group' => 'Деньги', 'items' => [
                $card('Выручка', $m($now['revenue']), $delta($now['revenue'], $before['revenue']), 'Платежи минус возвраты за период'),
                $card('MRR', $m($now['mrr']), $delta($now['mrr'], $before['mrr']), 'Ежемесячная выручка на конец периода'),
                $card('ARR', $m($now['arr']), $delta($now['arr'], $before['arr']), 'MRR × 12'),
                $card('Платящие', $n($now['paying']), $delta($now['paying'], $before['paying']), 'Новых за период: ' . $n($now['paying_new'])),
                $card('ARPPU', $m($now['arppu']), $delta($now['arppu'], $before['arppu']), 'Выручка в месяц на платящего'),
                $card('ARPU', $m($now['arpu']), $delta($now['arpu'], $before['arpu']), 'MRR на активного за месяц'),
                $card('LTV', $m($now['ltv']), $delta($now['ltv'], $before['ltv']), 'ARPPU / отток в месяц; без оттока оценки нет'),
                $card('Отток клиентов', $p($now['churn_logo']), null, 'Было: ' . $p($before['churn_logo']), false),
                $card('Отток выручки', $p($now['churn_revenue']), null, 'Было: ' . $p($before['churn_revenue']), false),
                $card('Возвраты', $m($now['refunds']), $delta($now['refunds'], $before['refunds']), null, false),
            ]];
        }
        if ($numbers) {
            $groups[] = ['group' => 'Пользователи', 'items' => [
                $card('Регистрации', $n($now['signups']), $delta($now['signups'], $before['signups'])),
                $card('Подтвердили почту', $n($now['verified']), $delta($now['verified'], $before['verified'])),
                $card('Активированные', $n($now['activated']), $delta($now['activated'], $before['activated']), 'Опубликовали первый пост'),
                $card('DAU (в среднем)', $n($now['dau']), $delta($now['dau'], $before['dau']), 'Активных в день'),
                $card('WAU', $n($now['wau']), $delta($now['wau'], $before['wau']), 'За 7 дней до конца периода'),
                $card('MAU', $n($now['mau']), $delta($now['mau'], $before['mau']), 'За 30 дней до конца периода'),
                $card('Пробный → платный', $p($now['trial_conversion']), null, 'Было: ' . $p($before['trial_conversion'])),
            ]];
            $groups[] = ['group' => 'Публикации', 'items' => [
                $card('Вышло', $n($now['pubs_sent']), $delta($now['pubs_sent'], $before['pubs_sent'])),
                $card('Не вышло', $n($now['pubs_failed']), $delta($now['pubs_failed'], $before['pubs_failed']), null, false),
                $card('Успешность', $p($now['pubs_success']), null, 'Было: ' . $p($before['pubs_success'])),
            ]];
        }

        return $groups;
    }

    /**
     * @param array{labels: list<string>, revenue: array<string, list<int>>, mrr: list<int>, movements: array<string, list<int>>, signups: array<string, list<int>>, plans: array<string, int>, bucket: string} $series
     * @return array<string, array<string, mixed>>
     */
    private function charts(array $series, string $currency, bool $money, bool $numbers): array
    {
        $labels = array_map(static fn (string $d): string => (new DateTimeImmutable($d))->format('d.m'), $series['labels']);
        $datasets = static function (array $rows, array $roles): array {
            $out = [];
            $i = 0;
            foreach ($rows as $name => $values) {
                $out[] = ['label' => (string) $name, 'data' => $values, 'role' => $roles[$name] ?? ['p', 'info', 'ok', 'warn', 'muted', 'bad'][$i % 6]];
                ++$i;
            }

            return $out;
        };
        $charts = [];
        if ($money) {
            $charts['revenue'] = ['title' => 'Выручка', 'type' => 'bar', 'stacked' => true, 'format' => 'money', 'currency' => $currency, 'labels' => $labels, 'datasets' => $datasets($series['revenue'], ['refunds' => 'bad'])];
            $charts['mrr'] = ['title' => 'MRR', 'type' => 'line', 'stacked' => false, 'format' => 'money', 'currency' => $currency, 'labels' => $labels, 'datasets' => [['label' => 'MRR', 'data' => $series['mrr'], 'role' => 'p']]];
            $charts['movements'] = ['title' => 'Движение MRR', 'type' => 'bar', 'stacked' => true, 'format' => 'money', 'currency' => $currency, 'labels' => $labels, 'datasets' => [
                ['label' => 'Новые', 'data' => $series['movements']['new'], 'role' => 'ok'],
                ['label' => 'Расширение', 'data' => $series['movements']['expansion'], 'role' => 'info'],
                ['label' => 'Сокращение', 'data' => $series['movements']['contraction'], 'role' => 'warn'],
                ['label' => 'Отток', 'data' => $series['movements']['churn'], 'role' => 'bad'],
            ]];
            $charts['plans'] = ['title' => 'Платящие по тарифам', 'type' => 'doughnut', 'stacked' => false, 'format' => 'int', 'currency' => $currency, 'labels' => array_keys($series['plans']), 'datasets' => [['label' => 'Платящих', 'data' => array_values($series['plans']), 'role' => 'p']]];
        }
        if ($numbers) {
            $charts['signups'] = ['title' => 'Регистрации по источникам', 'type' => 'bar', 'stacked' => true, 'format' => 'int', 'currency' => $currency, 'labels' => $labels, 'datasets' => $datasets($series['signups'], ['другие' => 'muted'])];
        }

        return $charts;
    }

    /**
     * Funnel steps with the share of the first step and of the previous one.
     *
     * @return list<array{label: string, count: int, of_first: ?float, of_previous: ?float}>
     */
    private function funnel(ReportFilters $filters): array
    {
        $steps = $this->reader->funnel($filters);
        $result = [];
        $first = null;
        $previous = null;
        foreach ($steps as $step) {
            $first ??= $step['count'];
            $result[] = [
                'label' => $step['label'],
                'count' => $step['count'],
                'of_first' => $first > 0 ? round($step['count'] / $first * 100, 1) : null,
                'of_previous' => $previous !== null && $previous > 0 ? round($step['count'] / $previous * 100, 1) : null,
            ];
            $previous = $step['count'];
        }

        return $result;
    }
}
