<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\DailyReport;
use App\Domain\Admin\StaffRole;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Settings\Settings;
use App\Domain\Support\Tickets;
use App\Http\Controllers\Admin\SearchController;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\MetricsFixture;
use App\Support\DbTime;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The owner's daily report (when it goes out, to whom, what it says) and the search box of the back office.
 */
#[CoversClass(DailyReport::class)]
#[CoversClass(SearchController::class)]
final class ReportAndSearchTest extends AdminTestCase
{
    private function report(): DailyReport
    {
        return $this->app->container()->get(DailyReport::class);
    }

    private function enable(int $hour = 9): void
    {
        $settings = $this->app->container()->get(Settings::class);
        $settings->set('report.enabled', true, null);
        $settings->set('report.hour', $hour, null);
        $settings->set('report.timezone', 'Europe/Moscow', null);
    }

    public function testTheReportGoesOutOnceAtTheChosenLocalHour(): void
    {
        $owner = $this->staff();
        $this->enable(9);
        $this->clock->set('2026-10-05 05:30:00');
        self::assertSame(0, $this->report()->tick(), '08:30 in Moscow: not yet');
        $this->clock->set('2026-10-05 06:10:00');
        self::assertSame(1, $this->report()->tick(), '09:10 in Moscow: now');
        $this->drainQueue();
        $mail = $this->mailer->lastTo((string) $owner->email);
        self::assertNotNull($mail);
        self::assertSame('Отчёт за вчера', $mail->subject);
        self::assertStringContainsString('Отчёт за 04.10.2026', $mail->text);
        self::assertStringContainsString('Выручка:', $mail->text);
        self::assertStringContainsString('Публикаций вышло:', $mail->text);

        $this->clock->set('2026-10-05 06:50:00');
        self::assertSame(0, $this->report()->tick(), 'one report per local day');
        $this->clock->set('2026-10-06 06:05:00');
        self::assertSame(1, $this->report()->tick(), 'and again the next day');

        $this->app->container()->get(Settings::class)->set('report.enabled', false, null);
        $this->clock->set('2026-10-07 06:05:00');
        self::assertSame(0, $this->report()->tick(), 'switched off');
    }

    public function testRecipientsAreOwnersAndChannelsFollowTheChoice(): void
    {
        $owner = $this->staff();
        $support = $this->staff(StaffRole::Support);
        $this->enable(9);
        $this->db->execute('INSERT INTO telegram_links (user_id, chat_id, linked_at) VALUES (?, 777, ?)', [$owner->id, DbTime::format($this->clock->now())]);
        $this->clock->set('2026-10-05 06:10:00');
        self::assertSame(1, $this->report()->tick());
        $telegram = fn (): int => count(array_filter($this->db->select('SELECT payload_json FROM jobs'), static fn (array $r): bool => str_contains((string) $r['payload_json'], 'SendTelegramNotificationJob')));
        self::assertSame(1, $telegram(), 'a message for the linked chat is queued');
        $this->drainQueue();
        self::assertSame([], $this->mailer->to((string) $support->email), 'support staff do not get the owner report');
        self::assertNotNull($this->mailer->lastTo((string) $owner->email));

        $this->db->execute('DELETE FROM jobs');
        $this->app->container()->get(Settings::class)->set('report.email', false, null);
        $this->app->container()->get(Settings::class)->set('report.last_sent', '2000-01-01', null);
        self::assertSame(1, $this->report()->tick());
        self::assertSame(1, $telegram(), 'with email off only the Telegram message is queued');
        self::assertSame(0, (int) $this->db->select("SELECT COUNT(*) AS c FROM jobs WHERE payload_json NOT LIKE '%SendTelegramNotificationJob%'")[0]['c'], 'and no email job');
    }

    public function testReportTextShowsYesterdaysMoney(): void
    {
        foreach (['metrics_daily', 'user_activity_days', 'user_attribution', 'payments', 'invoices'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
        (new MetricsFixture($this->db, $this->clock, $this->app->container()->get(Ledger::class), $this->app->container()->get(PaymentRepository::class)))->build();
        $this->clock->set('2026-09-11 08:00:00');
        $this->app->container()->get(\App\Domain\Analytics\MetricsAggregator::class)->range(new \DateTimeImmutable('2026-09-09'), new \DateTimeImmutable('2026-09-11'));
        $text = $this->report()->text();
        self::assertStringContainsString('Отчёт за 10.09.2026', $text);
        // 10 September: C paid a year of Pro (12 000 ₽).
        self::assertStringContainsString("Выручка: 12\u{00A0}000\u{00A0}₽", $text);
        self::assertStringContainsString('Оплат: 1', $text);
    }

    public function testSettingsSaveAndTestButton(): void
    {
        $owner = $this->staff();
        $this->post('/admin/settings/report', ['hour' => '25', 'timezone' => 'Europe/Moscow']);
        self::assertFalse($this->report()->config()['enabled']);
        $this->post('/admin/settings/report', ['hour' => '8', 'timezone' => 'Mars/Olympus']);
        self::assertFalse($this->report()->config()['enabled']);
        $this->post('/admin/settings/report', ['enabled' => '1', 'hour' => '8', 'timezone' => 'Asia/Vladivostok', 'email' => '1']);
        self::assertSame(['enabled' => true, 'hour' => 8, 'timezone' => 'Asia/Vladivostok', 'email' => true, 'telegram' => false, 'recipients' => []], $this->report()->config());
        self::assertStringContainsString('Ежедневный отчёт владельцу', $this->get('/admin/settings')->body);

        $this->post('/admin/settings/report/test', []);
        $this->drainQueue();
        self::assertNotNull($this->mailer->lastTo((string) $owner->email));
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->post('/admin/settings/report/test', [])->status);
    }

    public function testSearchFindsEachKindAndRespectsTheRole(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('findme@example.com', 'Ищем Меня');
        [, $payment] = $this->payWithFake($workspace, $owner);
        $ticket = $this->app->container()->get(Tickets::class)->open($owner, 'findme@example.com', 'Вопрос про findme', 'Текст', 'form');
        $this->staff();

        $page = $this->plain($this->get('/admin/search?q=findme'));
        self::assertStringContainsString('Люди', $page);
        self::assertStringContainsString('Ищем Меня', $page);
        self::assertStringContainsString('Платежи', $page);
        self::assertStringContainsString('Обращения', $page);

        self::assertSame('/admin/users/' . $owner->id, $this->get('/admin/search?q=' . $owner->id)->header('Location'), 'an exact number opens the person');
        self::assertSame('/admin/workspaces/' . $workspace->publicId, $this->get('/admin/search?q=' . $workspace->publicId)->header('Location'));
        self::assertSame('/admin/payments/' . $payment->publicId, $this->get('/admin/search?q=' . $payment->publicId)->header('Location'));
        self::assertSame('/admin/support/' . $ticket, $this->get('/admin/search?q=' . $ticket)->header('Location'));
        self::assertStringContainsString('Ничего не нашли', $this->plain($this->get('/admin/search?q=zzzznothing')));
        self::assertSame(200, $this->get('/admin/search?q=' . rawurlencode("'; DROP TABLE users; --"))->status);
        self::assertSame(200, $this->get('/admin/search')->status);

        $this->staff(StaffRole::Support);
        $support = $this->plain($this->get('/admin/search?q=findme'));
        self::assertStringContainsString('Люди', $support);
        self::assertStringNotContainsString('Платежи', $support, 'support does not search money');

        $this->staff(StaffRole::Analyst);
        $analyst = $this->plain($this->get('/admin/search?q=findme'));
        self::assertStringContainsString('Ничего не нашли', $analyst);
    }
}
