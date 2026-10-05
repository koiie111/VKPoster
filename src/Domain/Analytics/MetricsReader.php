<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything the business dashboard shows, read from `metrics_daily` (and, for the funnel and the retention cohorts, from the base tables).
 * Money is in minor units of one currency at a time. A ratio whose denominator is zero is `null` ("no data"), never a made-up zero.
 *
 * Definitions (also in `docs/architecture/admin-metrics.md`):
 *  - revenue = payments received minus refunds, in the period; MRR and paying customers are the state at the last day of the period;
 *  - ARR = MRR x 12; ARPU = MRR / MAU; ARPPU = MRR / paying customers;
 *  - logo churn = customers lost in the period / customers at the start; revenue churn = MRR lost / MRR at the start;
 *  - LTV = ARPPU / monthly logo churn (the period's churn scaled to 30 days); no churn means no estimate;
 *  - trial to paid = of the trials started in the period, the share whose workspace has paid since (a cohort, so never above 100%);
 *  - activated = workspaces that got a first publication out.
 */
final class MetricsReader
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * Currencies that have money rows, the most used first (RUB when there is nothing yet).
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        $found = [];
        foreach ($this->db->select("SELECT SUBSTRING_INDEX(dim, ':', -1) AS cur, COUNT(*) AS c FROM metrics_daily WHERE metric = 'revenue' GROUP BY cur ORDER BY c DESC") as $row) {
            $found[] = (string) $row['cur'];
        }

        return $found === [] ? ['RUB'] : $found;
    }

    /**
     * Plans, sources and networks that appear in the data, for the filter drop-downs.
     *
     * @return array{plans: list<string>, sources: list<string>, platforms: list<string>}
     */
    public function choices(): array
    {
        $plans = [];
        foreach ($this->db->select("SELECT DISTINCT SUBSTRING_INDEX(dim, ':', 1) AS plan FROM metrics_daily WHERE metric = 'mrr' ORDER BY plan") as $row) {
            $plans[] = (string) $row['plan'];
        }
        $sources = [];
        foreach ($this->db->select("SELECT dim, SUM(value) AS total FROM metrics_daily WHERE metric = 'signups' GROUP BY dim ORDER BY total DESC LIMIT 30") as $row) {
            $sources[] = (string) $row['dim'];
        }
        $platforms = [];
        foreach ($this->db->select("SELECT DISTINCT dim FROM metrics_daily WHERE metric IN ('pubs_sent', 'pubs_failed') ORDER BY dim") as $row) {
            $platforms[] = (string) $row['dim'];
        }

        return ['plans' => $plans, 'sources' => $sources, 'platforms' => $platforms];
    }

    /**
     * The headline numbers of the period, and the same numbers for the period before it.
     *
     * @return array{current: array<string, int|float|null>, previous: array<string, int|float|null>}
     */
    public function kpis(ReportFilters $f): array
    {
        return ['current' => $this->period($f), 'previous' => $this->period($f->previous())];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function period(ReportFilters $f): array
    {
        $from = $f->from->format('Y-m-d');
        $to = $f->to->format('Y-m-d');
        $gross = 0;
        $refunds = 0;
        foreach ($this->rows('revenue', $from, $to) as $row) {
            [$provider, $plan, $currency] = array_pad(explode(':', $row['dim'], 3), 3, '');
            if ($currency === $f->currency && ($f->plan === '' || $plan === $f->plan)) {
                $gross += $row['value'];
            }
        }
        foreach ($this->rows('refunds', $from, $to) as $row) {
            if (str_ends_with($row['dim'], ':' . $f->currency)) {
                $refunds += $row['value'];
            }
        }
        $mrrStart = $this->snapshot('mrr', $f->from->modify('-1 day'), $f);
        $mrr = $this->snapshot('mrr', $f->to, $f);
        $payingStart = $this->snapshot('paying', $f->from->modify('-1 day'), $f);
        $paying = $this->snapshot('paying', $f->to, $f);
        $mau = $this->scalarSnapshot('mau', $f->to);
        $churnedPaying = $this->sumCurrency('paying_churned', $from, $to, $f->currency);
        $churnedMrr = $this->sumCurrency('mrr_churn', $from, $to, $f->currency);
        [$trialsStarted, $trialsConverted] = $this->trials($f);
        $dauDays = $this->rows('dau', $from, $to);
        $sent = 0;
        $failed = 0;
        foreach ($this->rows('pubs_sent', $from, $to) as $row) {
            $sent += $f->platform === '' || $row['dim'] === $f->platform ? $row['value'] : 0;
        }
        foreach ($this->rows('pubs_failed', $from, $to) as $row) {
            $failed += $f->platform === '' || $row['dim'] === $f->platform ? $row['value'] : 0;
        }
        $signups = 0;
        foreach ($this->rows('signups', $from, $to) as $row) {
            $signups += $f->source === '' || $row['dim'] === $f->source ? $row['value'] : 0;
        }
        $monthlyChurn = $payingStart > 0 ? ($churnedPaying / $payingStart) * (30 / $f->days()) : null;
        $arppu = $paying > 0 ? intdiv($mrr, $paying) : null;

        return [
            'revenue' => $gross - $refunds,
            'revenue_gross' => $gross,
            'refunds' => $refunds,
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'paying' => $paying,
            'paying_new' => $this->sumCurrency('paying_new', $from, $to, $f->currency),
            'signups' => $signups,
            'verified' => $this->sum('verified', $from, $to),
            'activated' => $this->sum('activated', $from, $to),
            'dau' => $dauDays === [] ? null : round(array_sum(array_column($dauDays, 'value')) / max(1, $f->days()), 1),
            'wau' => $this->scalarSnapshot('wau', $f->to),
            'mau' => $mau,
            'trial_conversion' => $trialsStarted > 0 ? round($trialsConverted / $trialsStarted * 100, 1) : null,
            'churn_logo' => $payingStart > 0 ? round($churnedPaying / $payingStart * 100, 1) : null,
            'churn_revenue' => $mrrStart > 0 ? round($churnedMrr / $mrrStart * 100, 1) : null,
            'arpu' => $mau > 0 ? intdiv($mrr, $mau) : null,
            'arppu' => $arppu,
            'ltv' => $arppu !== null && $monthlyChurn !== null && $monthlyChurn > 0 ? (int) round($arppu / $monthlyChurn) : null,
            'pubs_sent' => $sent,
            'pubs_failed' => $failed,
            'pubs_success' => $sent + $failed > 0 ? round($sent / ($sent + $failed) * 100, 1) : null,
        ];
    }

    /**
     * Trials started in the period and how many of those workspaces have paid since the trial began.
     *
     * @return array{0: int, 1: int} started, converted
     */
    private function trials(ReportFilters $f): array
    {
        $row = $this->db->select(
            "SELECT COUNT(*) AS started, COALESCE(SUM(EXISTS (SELECT 1 FROM invoices i WHERE i.workspace_id = t.workspace_id AND i.status = 'paid' AND i.paid_at >= t.started_at)), 0) AS converted "
            . "FROM (SELECT workspace_id, MIN(created_at) AS started_at FROM audit_log WHERE action = 'billing.trial_started' AND created_at >= ? AND created_at < ? GROUP BY workspace_id) t",
            [DbTime::format($f->from), DbTime::format($f->to->modify('+1 day'))],
        )[0] ?? [];

        return [(int) ($row['started'] ?? 0), (int) ($row['converted'] ?? 0)];
    }

    /**
     * Day-by-day series for the charts. Long periods are cut into weeks so a chart stays readable.
     *
     * @return array{labels: list<string>, revenue: array<string, list<int>>, mrr: list<int>, movements: array<string, list<int>>, signups: array<string, list<int>>, plans: array<string, int>, bucket: string}
     */
    public function series(ReportFilters $f): array
    {
        $from = $f->from->format('Y-m-d');
        $to = $f->to->format('Y-m-d');
        $weekly = $f->days() > 92;
        $bucket = static fn (string $day): string => $weekly
            ? (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d')
            : $day;
        $labels = [];
        for ($d = $f->from; $d <= $f->to; $d = $d->modify('+1 day')) {
            $labels[$bucket($d->format('Y-m-d'))] = true;
        }
        $keys = array_keys($labels);
        $zero = array_fill_keys($keys, 0);

        $revenue = [];
        foreach ($this->rows('revenue', $from, $to) as $row) {
            [$provider, $plan, $currency] = array_pad(explode(':', $row['dim'], 3), 3, '');
            if ($currency !== $f->currency || ($f->plan !== '' && $plan !== $f->plan)) {
                continue;
            }
            $revenue[$provider] ??= $zero;
            $revenue[$provider][$bucket($row['day'])] += $row['value'];
        }
        foreach ($this->rows('refunds', $from, $to) as $row) {
            [$provider, $currency] = array_pad(explode(':', $row['dim'], 2), 2, '');
            if ($currency === $f->currency && $f->plan === '') {
                $revenue['refunds'] ??= $zero;
                $revenue['refunds'][$bucket($row['day'])] -= $row['value'];
            }
        }

        // MRR is a state: the last day of each bucket.
        $mrrByDay = [];
        foreach ($this->rows('mrr', $from, $to) as $row) {
            [$plan, $currency] = array_pad(explode(':', $row['dim'], 2), 2, '');
            if ($currency === $f->currency && ($f->plan === '' || $plan === $f->plan)) {
                $mrrByDay[$row['day']] = ($mrrByDay[$row['day']] ?? 0) + $row['value'];
            }
        }
        ksort($mrrByDay);
        $mrr = $zero;
        foreach ($mrrByDay as $day => $value) {
            $mrr[$bucket($day)] = $value;
        }

        $movements = ['new' => $zero, 'expansion' => $zero, 'contraction' => $zero, 'churn' => $zero];
        foreach (['mrr_new' => ['new', 1], 'mrr_expansion' => ['expansion', 1], 'mrr_contraction' => ['contraction', -1], 'mrr_churn' => ['churn', -1]] as $metric => [$name, $sign]) {
            foreach ($this->rows($metric, $from, $to) as $row) {
                if ($row['dim'] === $f->currency) {
                    $movements[$name][$bucket($row['day'])] += $sign * $row['value'];
                }
            }
        }

        $totals = [];
        $rows = $this->rows('signups', $from, $to);
        foreach ($rows as $row) {
            $totals[$row['dim']] = ($totals[$row['dim']] ?? 0) + $row['value'];
        }
        arsort($totals);
        $top = array_slice(array_keys($totals), 0, 6);
        $signups = [];
        foreach ($rows as $row) {
            if ($f->source !== '' && $row['dim'] !== $f->source) {
                continue;
            }
            $name = in_array($row['dim'], $top, true) ? $row['dim'] : 'другие';
            $signups[$name] ??= $zero;
            $signups[$name][$bucket($row['day'])] += $row['value'];
        }

        $plans = [];
        $lastDay = $this->lastDay('paying', $f->to);
        if ($lastDay !== null) {
            foreach ($this->db->select("SELECT dim, value FROM metrics_daily WHERE metric = 'paying' AND day = ?", [$lastDay]) as $row) {
                [$plan, $currency] = array_pad(explode(':', (string) $row['dim'], 2), 2, '');
                if ($currency === $f->currency) {
                    $plans[$plan] = ($plans[$plan] ?? 0) + (int) $row['value'];
                }
            }
        }
        arsort($plans);

        return [
            'labels' => $keys,
            'revenue' => array_map('array_values', $revenue),
            'mrr' => array_values($mrr),
            'movements' => array_map('array_values', $movements),
            'signups' => array_map('array_values', $signups),
            'plans' => $plans,
            'bucket' => $weekly ? 'week' : 'day',
        ];
    }

    /**
     * People who registered in the period and how far they got (each step counts people from that group who did it, ever).
     * The first step is the number of visits in the period.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function funnel(ReportFilters $f): array
    {
        $from = DbTime::format($f->from);
        $to = DbTime::format($f->to->modify('+1 day'));
        $where = 'u.created_at >= ? AND u.created_at < ?';
        $bindings = [$from, $to];
        if ($f->source !== '') {
            $where .= " AND COALESCE(NULLIF(a.utm_source, ''), NULLIF(a.referrer, ''), 'direct') = ?";
            $bindings[] = $f->source;
        }
        $base = 'FROM users u LEFT JOIN user_attribution a ON a.user_id = u.id WHERE ' . $where;
        $owned = 'EXISTS (SELECT 1 FROM workspaces w WHERE w.owner_id = u.id AND %s)';
        $steps = [
            ['registered', 'Зарегистрировались', ''],
            ['verified', 'Подтвердили почту', ' AND u.email_verified_at IS NOT NULL'],
            ['channel', 'Подключили канал', ' AND ' . sprintf($owned, 'EXISTS (SELECT 1 FROM channels c WHERE c.workspace_id = w.id)')],
            ['post', 'Запланировали пост', ' AND ' . sprintf($owned, "EXISTS (SELECT 1 FROM posts p WHERE p.workspace_id = w.id AND p.status <> 'draft')")],
            ['paid', 'Оплатили', ' AND ' . sprintf($owned, "EXISTS (SELECT 1 FROM invoices i WHERE i.workspace_id = w.id AND i.status = 'paid')")],
        ];
        $visitSql = "SELECT COUNT(*) AS c FROM analytics_events WHERE name = 'visit' AND occurred_at >= ? AND occurred_at < ?";
        $visitBindings = [$from, $to];
        if ($f->source !== '') {
            $visitSql .= " AND JSON_UNQUOTE(JSON_EXTRACT(props_json, '$.source')) = ?";
            $visitBindings[] = $f->source;
        }
        $result = [['key' => 'visit', 'label' => 'Зашли на сайт', 'count' => (int) ($this->db->select($visitSql, $visitBindings)[0]['c'] ?? 0)]];
        foreach ($steps as [$key, $label, $extra]) {
            $result[] = ['key' => $key, 'label' => $label, 'count' => (int) ($this->db->select('SELECT COUNT(*) AS c ' . $base . $extra, $bindings)[0]['c'] ?? 0)];
        }

        return $result;
    }

    /**
     * Weekly retention: for each week of sign-ups, the share of those people who came back in the n-th week after signing up.
     *
     * @return list<array{cohort: string, size: int, weeks: list<float|null>}> newest cohort last; `weeks[0]` is the sign-up week itself
     */
    public function cohorts(int $weeks = 8): array
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $start = $today->modify('monday this week')->modify('-' . ($weeks - 1) . ' weeks');
        $from = DbTime::format($start);
        $sizes = [];
        foreach ($this->db->select(
            'SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS cohort, COUNT(*) AS c FROM users WHERE created_at >= ? GROUP BY cohort',
            [$from],
        ) as $row) {
            $sizes[(string) $row['cohort']] = (int) $row['c'];
        }
        $returned = [];
        foreach ($this->db->select(
            'SELECT DATE_SUB(DATE(u.created_at), INTERVAL WEEKDAY(u.created_at) DAY) AS cohort, FLOOR(DATEDIFF(d.day, DATE(u.created_at)) / 7) AS k, COUNT(DISTINCT u.id) AS c '
            . 'FROM users u JOIN user_activity_days d ON d.user_id = u.id WHERE u.created_at >= ? AND d.day >= DATE(u.created_at) GROUP BY cohort, k',
            [$from],
        ) as $row) {
            $returned[(string) $row['cohort']][(int) $row['k']] = (int) $row['c'];
        }
        $result = [];
        for ($week = $start; $week <= $today; $week = $week->modify('+1 week')) {
            $key = $week->format('Y-m-d');
            $size = $sizes[$key] ?? 0;
            $row = [];
            $elapsed = (int) floor(($today->getTimestamp() - $week->getTimestamp()) / 604800);
            for ($k = 0; $k <= $weeks - 1; $k++) {
                $row[] = $k > $elapsed || $size === 0 ? null : round(($returned[$key][$k] ?? 0) / $size * 100, 1);
            }
            $result[] = ['cohort' => $key, 'size' => $size, 'weeks' => $row];
        }

        return $result;
    }

    /**
     * @return list<array{day: string, dim: string, value: int}>
     */
    private function rows(string $metric, string $from, string $to): array
    {
        $rows = [];
        foreach ($this->db->select('SELECT day, dim, value FROM metrics_daily WHERE metric = ? AND day BETWEEN ? AND ? ORDER BY day', [$metric, $from, $to]) as $row) {
            $rows[] = ['day' => (string) $row['day'], 'dim' => (string) $row['dim'], 'value' => (int) $row['value']];
        }

        return $rows;
    }

    private function sum(string $metric, string $from, string $to): int
    {
        return (int) ($this->db->select('SELECT COALESCE(SUM(value), 0) AS v FROM metrics_daily WHERE metric = ? AND day BETWEEN ? AND ?', [$metric, $from, $to])[0]['v'] ?? 0);
    }

    private function sumCurrency(string $metric, string $from, string $to, string $currency): int
    {
        return (int) ($this->db->select('SELECT COALESCE(SUM(value), 0) AS v FROM metrics_daily WHERE metric = ? AND dim = ? AND day BETWEEN ? AND ?', [$metric, $currency, $from, $to])[0]['v'] ?? 0);
    }

    /**
     * The day (not later than `$day`) that has a value of a state metric: a missed aggregation falls back to the last one that ran.
     */
    private function lastDay(string $metric, DateTimeImmutable $day): ?string
    {
        $found = $this->db->select('SELECT MAX(day) AS d FROM metrics_daily WHERE metric = ? AND day <= ?', [$metric, $day->format('Y-m-d')])[0]['d'] ?? null;

        return is_string($found) ? $found : null;
    }

    /**
     * A state metric (MRR, paying customers) at the end of a day, for the plan and currency of the filters.
     */
    private function snapshot(string $metric, DateTimeImmutable $day, ReportFilters $f): int
    {
        $date = $this->lastDay($metric, $day);
        if ($date === null) {
            return 0;
        }
        $total = 0;
        foreach ($this->db->select('SELECT dim, value FROM metrics_daily WHERE metric = ? AND day = ?', [$metric, $date]) as $row) {
            [$plan, $currency] = array_pad(explode(':', (string) $row['dim'], 2), 2, '');
            if ($currency === $f->currency && ($f->plan === '' || $plan === $f->plan)) {
                $total += (int) $row['value'];
            }
        }

        return $total;
    }

    private function scalarSnapshot(string $metric, DateTimeImmutable $day): int
    {
        $date = $this->lastDay($metric, $day);

        return $date === null ? 0 : (int) ($this->db->select("SELECT COALESCE(SUM(value), 0) AS v FROM metrics_daily WHERE metric = ? AND day = ?", [$metric, $date])[0]['v'] ?? 0);
    }
}
