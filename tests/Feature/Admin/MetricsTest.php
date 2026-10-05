<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Analytics\MetricsReader;
use App\Domain\Analytics\MrrCalculator;
use App\Domain\Analytics\ReportFilters;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentRepository;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\MetricsFixture;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The business numbers are checked against a month whose answers are known (`MetricsFixture`): MRR, revenue, churn, trials, activity,
 * the funnel and the retention cohorts; and the aggregation must give the same rows when it is run again.
 */
#[CoversClass(MetricsAggregator::class)]
#[CoversClass(MetricsReader::class)]
#[CoversClass(MrrCalculator::class)]
#[CoversClass(ReportFilters::class)]
final class MetricsTest extends AdminTestCase
{
    private MetricsFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['metrics_daily', 'analytics_events', 'user_activity_days', 'user_attribution', 'payments', 'invoices'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
        $this->fixture = new MetricsFixture($this->db, $this->clock, $this->app->container()->get(Ledger::class), $this->app->container()->get(PaymentRepository::class));
        $this->fixture->build();
        $this->clock->set('2026-10-01 03:00:00');
        $this->aggregator()->range(self::day('2026-08-15'), self::day('2026-09-30'));
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private function aggregator(): MetricsAggregator
    {
        return $this->app->container()->get(MetricsAggregator::class);
    }

    private function reader(): MetricsReader
    {
        return $this->app->container()->get(MetricsReader::class);
    }

    private function september(string $plan = '', string $source = ''): ReportFilters
    {
        return new ReportFilters(self::day('2026-09-01'), self::day('2026-09-30'), $plan, '', $source, 'RUB');
    }

    /**
     * @return array<string, int>
     */
    private function metric(string $metric, string $day): array
    {
        $out = [];
        foreach ($this->db->select('SELECT dim, value FROM metrics_daily WHERE metric = ? AND day = ?', [$metric, $day]) as $row) {
            $out[(string) $row['dim']] = (int) $row['value'];
        }

        return $out;
    }

    public function testMrrPerDayFollowsThePaidPeriods(): void
    {
        $mrr = $this->app->container()->get(MrrCalculator::class);
        $end = static fn (string $day): DateTimeImmutable => self::day($day)->modify('+1 day')->modify('-1 microsecond');
        self::assertSame(60000, array_sum(array_column($mrr->at($end('2026-08-31')), 'mrr')), 'D and E before September');
        self::assertSame(['plan' => 'pro', 'currency' => 'RUB', 'mrr' => 100000], $mrr->at($end('2026-09-10'))[$this->fixture->workspaces['C']], 'a yearly price is spread over 12 months');
        self::assertSame(100000, $mrr->at($end('2026-09-16'))[$this->fixture->workspaces['F']]['mrr'], 'the upgrade replaces the old invoice');
        self::assertSame(30000, $mrr->at($end('2026-09-14'))[$this->fixture->workspaces['F']]['mrr']);
        self::assertArrayNotHasKey($this->fixture->workspaces['G'], $mrr->at($end('2026-09-12')), 'a refunded payment never counts');
        self::assertArrayNotHasKey($this->fixture->workspaces['E'], $mrr->at($end('2026-09-25')), 'E was not renewed');
        self::assertArrayHasKey($this->fixture->workspaces['E'], $mrr->at($end('2026-09-24')));
    }

    public function testEndOfMonthKpisMatchTheReferenceAnswers(): void
    {
        $kpis = $this->reader()->kpis($this->september());
        $now = $kpis['current'];
        self::assertSame(360000, $now['mrr']);
        self::assertSame(4320000, $now['arr']);
        self::assertSame(5, $now['paying']);
        self::assertSame(1525000, $now['revenue_gross']);
        self::assertSame(100000, $now['refunds']);
        self::assertSame(1425000, $now['revenue']);
        self::assertSame(4, $now['paying_new'], 'A, B, C and F started paying in September');
        self::assertSame(50.0, $now['churn_logo'], 'E of D and E');
        self::assertSame(50.0, $now['churn_revenue']);
        self::assertSame(72000, $now['arppu']);
        self::assertSame(3, $now['mau']);
        self::assertSame(120000, $now['arpu']);
        self::assertSame(66.7, $now['trial_conversion'], 'B and C paid, H did not');
        // Monthly logo churn 50% -> LTV = ARPPU / 0.5 (the period is 30 days long, so no scaling).
        self::assertSame(144000, $now['ltv']);
        self::assertSame(4, $now['signups'], 'C, F, G and H registered in September; A and B in August');
    }

    public function testComparisonWithThePreviousPeriod(): void
    {
        $previous = $this->reader()->kpis($this->september())['previous'];
        // August (the 31 days before September, from 08-02): revenue D 30000 (08-20) and E 30000 (08-25); MRR at the end: D and E.
        self::assertSame(60000, $previous['revenue']);
        self::assertSame(60000, $previous['mrr']);
        self::assertSame(2, $previous['paying']);
    }

    public function testFiltersNarrowRevenueAndMrrByPlan(): void
    {
        $pro = $this->reader()->kpis($this->september('pro'))['current'];
        self::assertSame(100000 + 100000 + 100000, $pro['mrr'], 'A, C and F are on pro at the end');
        self::assertSame(100000 + 1200000 + 35000 + 100000, $pro['revenue_gross']);
        $start = $this->reader()->kpis($this->september('start'))['current'];
        self::assertSame(60000, $start['mrr'], 'B and D');
        $usd = $this->reader()->kpis(new ReportFilters(self::day('2026-09-01'), self::day('2026-09-30'), '', '', '', 'USD'))['current'];
        self::assertSame(0, $usd['mrr'], 'currencies are never added together');
    }

    public function testDailyRowsMovementsAndActivity(): void
    {
        self::assertSame(['RUB' => 130000], $this->metric('mrr_new', '2026-09-01'));
        self::assertSame(['RUB' => 70000], $this->metric('mrr_expansion', '2026-09-15'));
        self::assertSame(['RUB' => 30000], $this->metric('mrr_churn', '2026-09-25'));
        self::assertSame(['RUB' => 1], $this->metric('paying_churned', '2026-09-25'));
        self::assertSame([], $this->metric('mrr_churn', '2026-09-26'));
        self::assertSame(['yookassa:pro:RUB' => 1200000], $this->metric('revenue', '2026-09-10'));
        self::assertSame(['yookassa:RUB' => 100000], $this->metric('refunds', '2026-09-13'));
        self::assertSame(['' => 3], $this->metric('dau', '2026-09-10'));
        self::assertSame(['' => 3], $this->metric('wau', '2026-09-10'));
        self::assertSame(['' => 1], $this->metric('wau', '2026-09-17'));
        self::assertSame(['' => 3], $this->metric('mau', '2026-09-30'));
        self::assertSame(['vk-ads' => 1], $this->metric('signups', '2026-09-01'));
        self::assertSame(['news.example.com' => 1], $this->metric('signups', '2026-09-02'));
        self::assertSame(['direct' => 1], $this->metric('signups', '2026-09-11'));
        self::assertSame(['' => 1], $this->metric('trials_started', '2026-09-01'));
        self::assertSame(['' => 1], $this->metric('first_paid', '2026-09-12'), 'G paid for the first time');
    }

    public function testAggregationIsIdempotent(): void
    {
        $dump = fn (): array => $this->db->select('SELECT day, metric, dim, value FROM metrics_daily ORDER BY day, metric, dim');
        $first = $dump();
        self::assertNotEmpty($first);
        $this->aggregator()->range(self::day('2026-08-15'), self::day('2026-09-30'));
        $this->aggregator()->range(self::day('2026-09-25'), self::day('2026-09-30'));
        self::assertSame($first, $dump());
        // The scheduler's job recomputes the last three days including today and leaves older days alone.
        $this->clock->set('2026-09-30 22:00:00');
        $rows = $this->aggregator()->recent(3);
        self::assertGreaterThan(0, $rows);
        self::assertSame($first, $dump());
    }

    public function testFunnelOfSeptemberSignups(): void
    {
        $steps = array_column($this->reader()->funnel($this->september()), 'count', 'key');
        // Registered in September: C, F, G, H.
        self::assertSame(4, $steps['registered']);
        self::assertSame(3, $steps['verified'], 'H never confirmed the address');
        self::assertSame(0, $steps['channel']);
        self::assertSame(3, $steps['paid'], 'C, F and G paid (G was refunded afterwards)');
        $vk = array_column($this->reader()->funnel($this->september('', 'vk-ads')), 'count', 'key');
        self::assertSame(1, $vk['registered'], 'only F came from vk-ads in September');
    }

    public function testRetentionCohortsAreWeeklyAndSkipTheFuture(): void
    {
        $this->clock->set('2026-09-30 12:00:00');
        $cohorts = $this->reader()->cohorts(8);
        self::assertCount(8, $cohorts);
        $byWeek = array_column($cohorts, null, 'cohort');
        // Week of 2026-08-24: E registered 08-24 and A 08-30 (B registered on Monday 08-31, so B is in the next week). A was active
        // on 09-04 (5 days after signing up: week 0) and on 09-10 and 09-11 (11 and 12 days: week 1); E never came back.
        $row = $byWeek['2026-08-24'];
        self::assertSame(2, $row['size']);
        self::assertSame(50.0, $row['weeks'][0]);
        self::assertSame(50.0, $row['weeks'][1]);
        self::assertSame(0.0, $row['weeks'][2]);
        // Week of 08-31: B (08-31), F (09-01) and C (09-02); B and C were active 09-10 (week 1), C again on 09-20 (week 2).
        self::assertSame(3, $byWeek['2026-08-31']['size']);
        self::assertSame(66.7, $byWeek['2026-08-31']['weeks'][1]);
        self::assertSame(33.3, $byWeek['2026-08-31']['weeks'][2]);
        self::assertNull($cohorts[array_key_last($cohorts)]['weeks'][5], 'future weeks are blank');
    }

    public function testEventsAreWrittenOnceAndFromAuditEntries(): void
    {
        $analytics = $this->app->container()->get(\App\Domain\Analytics\Analytics::class);
        self::assertTrue($analytics->track('first_post_published', 1, 2, [], 'first_post_published:2'));
        self::assertFalse($analytics->track('first_post_published', 1, 2, [], 'first_post_published:2'), 'the same key twice is ignored');
        $audit = $this->app->container()->get(\App\Domain\Audit\AuditLog::class);
        $audit->record('billing.payment_succeeded', null, 'invoice', 'INV1', ['plan' => 'pro', 'period' => 'month', 'amount' => 100, 'kind' => 'upgrade', 'provider' => 'yookassa'], null);
        $audit->record('billing.payment_succeeded', null, 'invoice', 'INV1', ['plan' => 'pro', 'kind' => 'upgrade'], null);
        $names = array_column($this->db->select('SELECT name FROM analytics_events ORDER BY id'), 'name');
        self::assertSame(['first_post_published', 'subscription_upgraded'], $names);
    }
}
