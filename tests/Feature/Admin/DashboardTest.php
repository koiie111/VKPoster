<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Analytics\ActivityTracker;
use App\Domain\Analytics\FirstTouch;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentRepository;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Middleware\TrackVisit;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\MetricsFixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The dashboard page: who sees money, filters, the empty state, and how visits, sign-up origin and daily activity are recorded.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(FirstTouch::class)]
#[CoversClass(ActivityTracker::class)]
#[CoversClass(TrackVisit::class)]
final class DashboardTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['metrics_daily', 'analytics_events', 'user_activity_days', 'user_attribution', 'payments', 'invoices'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
    }

    private function seedSeptember(): void
    {
        (new MetricsFixture($this->db, $this->clock, $this->app->container()->get(Ledger::class), $this->app->container()->get(PaymentRepository::class)))->build();
        $this->clock->set('2026-10-01 03:00:00');
        $this->app->container()->get(\App\Domain\Analytics\MetricsAggregator::class)->range(new \DateTimeImmutable('2026-08-20'), new \DateTimeImmutable('2026-10-01'));
    }

    public function testEmptyDashboardExplainsWhatToDo(): void
    {
        $this->staff();
        $page = $this->get('/admin');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Пересчитать цифры', $page->body);
        self::assertStringContainsString('Деньги', $page->body);
    }

    public function testOwnerSeesMoneyAndFiltersWork(): void
    {
        $this->seedSeptember();
        $this->staff();
        $page = $this->plain($this->get('/admin?period=custom&from=2026-09-01&to=2026-09-30'));
        self::assertStringContainsString('MRR 3 600 ₽', $page, 'MRR');
        self::assertStringContainsString('Выручка 14 250 ₽', $page, 'revenue');
        self::assertStringContainsString('ARR 43 200 ₽', $page, 'ARR');
        self::assertStringContainsString('66,7%', $page, 'trial conversion');
        self::assertStringContainsString('Воронка', $page);
        self::assertStringContainsString('Удержание по неделям', $page);

        $pro = $this->plain($this->get('/admin?period=custom&from=2026-09-01&to=2026-09-30&plan=pro'));
        self::assertStringContainsString('MRR 3 000 ₽', $pro, 'MRR of the pro plan');
        // A filter value that is not in the data is ignored instead of breaking the page.
        self::assertSame(200, $this->get('/admin?plan=%27%3Bdrop&currency=XXX&source=x&period=weird&from=nope')->status);
        self::assertSame(200, $this->get('/admin?period=custom&from=2026-09-30&to=2026-09-01')->status);
    }

    public function testChartsComeWithTheirDataAndTheScriptsAreLocal(): void
    {
        $this->seedSeptember();
        $this->staff();
        $body = $this->get('/admin?period=custom&from=2026-09-01&to=2026-09-30')->body;
        self::assertStringContainsString('vendor/chart.umd.min.js', $body);
        self::assertStringContainsString('js/admin-charts.js', $body);
        self::assertStringNotContainsString('cdn.', $body);
        self::assertMatchesRegularExpression('/<canvas id="chart-mrr"[^>]*data-chart="[^"]+"/', $body);
        self::assertStringContainsString('chart-signups', $body);
    }

    public function testRolesSeeOnlyWhatTheyMay(): void
    {
        $this->seedSeptember();
        $this->staff(StaffRole::Analyst);
        $analyst = $this->plain($this->get('/admin?period=custom&from=2026-09-01&to=2026-09-30'));
        self::assertStringNotContainsString('MRR', $analyst, 'an analyst has no finance permission');
        self::assertStringContainsString('Регистрации', $analyst);

        $this->staff(StaffRole::Finance);
        $finance = $this->plain($this->get('/admin?period=custom&from=2026-09-01&to=2026-09-30'));
        self::assertStringContainsString('MRR', $finance);

        $this->staff(StaffRole::Content);
        $content = $this->plain($this->get('/admin'));
        self::assertStringNotContainsString('MRR', $content);
        self::assertStringNotContainsString('Регистрации', $content);
        self::assertStringContainsString('Пользователей', $content);
        self::assertSame(403, $this->post('/admin/dashboard/refresh', [])->status, 'a content editor may not trigger the recount');
    }

    public function testRefreshRecountsAndRemembersWhen(): void
    {
        $this->seedSeptember();
        $this->db->execute('DELETE FROM metrics_daily');
        $this->clock->set('2026-09-30 12:00:00');
        $this->staff();
        $response = $this->post('/admin/dashboard/refresh', ['back' => '//evil.example.com']);
        self::assertSame('/admin', $response->header('Location'), 'an outside address is never followed');
        self::assertGreaterThan(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM metrics_daily')[0]['c']);
        self::assertStringContainsString('цифры обновлены', $this->get('/admin')->body);
    }

    public function testFirstTouchVisitAndAttributionAtSignUp(): void
    {
        $this->useBrowser();
        $browser = ['User-Agent' => 'Mozilla/5.0 (X11; Linux) Firefox/130.0', 'Referer' => 'https://www.News.Example.com/some/path?secret=1'];
        $this->get('/?utm_source=VK%20Ads&utm_medium=cpc&utm_campaign=autumn', $browser);
        // A second page of the same session does not overwrite the origin or count a second visit.
        $this->get('/register?utm_source=other', $browser);
        $visits = $this->db->select("SELECT visitor_id, props_json FROM analytics_events WHERE name = 'visit'");
        self::assertCount(1, $visits);
        self::assertSame(['source' => 'vk_ads'], json_decode((string) $visits[0]['props_json'], true));

        $this->post('/register', ['email' => 'new@example.com', 'name' => 'Новый', 'password' => self::PASSWORD, 'consent' => '1']);
        $user = $this->db->select("SELECT id FROM users WHERE email = 'new@example.com'")[0];
        $attribution = $this->db->select('SELECT * FROM user_attribution WHERE user_id = ?', [$user['id']])[0];
        self::assertSame('vk_ads', $attribution['utm_source']);
        self::assertSame('cpc', $attribution['utm_medium']);
        self::assertSame('autumn', $attribution['utm_campaign']);
        self::assertSame('news.example.com', $attribution['referrer'], 'only the host of the referrer is kept');
        self::assertSame('/', $attribution['landing']);
        self::assertSame($visits[0]['visitor_id'], $attribution['visitor_id']);
        self::assertEqualsCanonicalizing(['user_registered', 'trial_started'], array_column($this->db->select("SELECT name FROM analytics_events WHERE name <> 'visit'"), 'name'));
    }

    public function testRobotsAreNotCountedAsVisits(): void
    {
        $this->useBrowser();
        $this->get('/', ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $this->get('/register', ['User-Agent' => 'curl/8.0']);
        self::assertSame(0, (int) $this->db->select("SELECT COUNT(*) AS c FROM analytics_events WHERE name = 'visit'")[0]['c']);
        self::assertSame('', FirstTouch::tag(['x'], 10));
        self::assertSame("a_b_drop-c", FirstTouch::tag("  A b'; DROP-c ", 30));
        self::assertSame('a_b_', FirstTouch::tag('A b DROP', 4));
        self::assertSame('', FirstTouch::referrerHost('https://localhost/page', 'localhost:8080'));
        self::assertSame('', FirstTouch::referrerHost('not a url', 'localhost'));
    }

    public function testDailyActivityIsRecordedOncePerDayAndNotWhileImpersonating(): void
    {
        $this->clock->set('2026-09-30 23:30:00');
        [$user] = $this->ownerWithWorkspace('active@example.com');
        $this->actAs($user);
        $this->get('/app');
        $this->get('/app');
        $day = $this->clock->now()->format('Y-m-d');
        self::assertSame([['user_id' => $user->id, 'day' => $day]], array_map(static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'day' => (string) $r['day']], $this->db->select('SELECT user_id, day FROM user_activity_days')));
        $this->clock->advance(1800);
        $this->get('/app');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_activity_days WHERE user_id = ?', [$user->id])[0]['c']);

        $this->db->execute('DELETE FROM user_activity_days');
        $staff = $this->staff();
        $this->post('/admin/users/' . $user->id . '/impersonate', $this->confirm());
        $this->get('/app');
        self::assertSame([$staff->id], array_map(static fn (array $r): int => (int) $r['user_id'], $this->db->select('SELECT user_id FROM user_activity_days')), 'the customer is not made "active" by support');
    }
}
