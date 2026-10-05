<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Campaign\Campaigns;
use App\Domain\Campaign\SendCampaignBatchJob;
use App\Domain\Notification\MarketingConsent;
use App\Domain\Settings\Settings;
use App\Domain\Support\Tickets;
use App\Http\Controllers\Admin\CampaignsController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Site\UnsubscribeController;
use App\Support\DbTime;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Talking to customers: marketing consent, email campaigns (segment, test, throttled sending, unsubscribe) and support tickets.
 */
#[CoversClass(Campaigns::class)]
#[CoversClass(SendCampaignBatchJob::class)]
#[CoversClass(MarketingConsent::class)]
#[CoversClass(CampaignsController::class)]
#[CoversClass(UnsubscribeController::class)]
#[CoversClass(Tickets::class)]
#[CoversClass(SupportController::class)]
final class CommsAdminTest extends AdminTestCase
{
    /**
     * A verified person who agreed (or not) to news.
     */
    private function person(string $email, bool $optIn = true, bool $verified = true): int
    {
        $user = $this->createUser($email, $verified, null, 'Человек ' . $email);
        if ($optIn) {
            $this->app->container()->get(MarketingConsent::class)->subscribe($user->id);
        }

        return $user->id;
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function campaign(string $body = 'Привет, {name}! Новости.', array $segment = []): string
    {
        return $this->app->container()->get(Campaigns::class)->create('Осенняя', 'Новости осени', $body, $segment, null);
    }

    public function testConsentAtSignUpAndInTheAccount(): void
    {
        $this->useBrowser();
        $base = ['name' => 'Мария', 'password' => 'a-long-unusual-passphrase', 'consent' => '1'];
        $this->post('/register', $base + ['email' => 'yes@example.com', 'marketing' => '1']);
        $this->post('/register', $base + ['email' => 'no@example.com']);
        $consent = $this->app->container()->get(MarketingConsent::class);
        $yes = (int) $this->db->select("SELECT id FROM users WHERE email = 'yes@example.com'")[0]['id'];
        $no = (int) $this->db->select("SELECT id FROM users WHERE email = 'no@example.com'")[0]['id'];
        self::assertTrue($consent->isSubscribed($yes));
        self::assertFalse($consent->isSubscribed($no), 'the box is off unless ticked');

        $user = $this->createUser('toggle@example.com');
        $this->actAs($user);
        $this->post('/account/notifications/marketing', ['marketing' => '1']);
        self::assertTrue($consent->isSubscribed($user->id));
        self::assertStringContainsString('Новости и предложения', $this->get('/account/notifications')->body);
        $this->post('/account/notifications/marketing', []);
        self::assertFalse($consent->isSubscribed($user->id));
    }

    public function testSegmentOnlyHoldsPeopleWhoAgreedAndStayedSubscribed(): void
    {
        $in = $this->person('in@example.com');
        $this->person('nooptin@example.com', false);
        $this->person('unverified@example.com', true, false);
        $left = $this->person('left@example.com');
        $this->app->container()->get(MarketingConsent::class)->unsubscribe($left);
        $blocked = $this->person('blocked@example.com');
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setStatus($blocked, 'blocked', 'x');
        $campaigns = $this->app->container()->get(Campaigns::class);
        self::assertSame(1, $campaigns->count([]));

        // Narrowing by plan, network and activity.
        [$owner, $workspace] = $this->ownerWithWorkspace('owner@example.com', 'Владелец');
        $this->app->container()->get(MarketingConsent::class)->subscribe($owner->id);
        $this->givePlan($workspace, 'pro');
        $this->fakeChannel($workspace, $owner, 'c-1', 'Канал');
        self::assertSame(2, $campaigns->count([]));
        self::assertSame(1, $campaigns->count(['plans' => ['pro']]));
        self::assertSame(0, $campaigns->count(['plans' => ['agency']]));
        self::assertSame(1, $campaigns->count(['platforms' => ['fake']]));
        self::assertSame(0, $campaigns->count(['platforms' => ['vk']]));
        $this->db->execute('INSERT INTO user_activity_days (user_id, day) VALUES (?, ?)', [$in, $this->clock->now()->format('Y-m-d')]);
        self::assertSame(1, $campaigns->count(['activity' => 'active']));
        self::assertSame(1, $campaigns->count(['activity' => 'inactive']));
        // Junk in the segment is dropped, not run as SQL.
        self::assertSame(2, $campaigns->count(['plans' => ["x'; DROP TABLE users; --"], 'activity' => 'whenever']));
        self::assertSame(['plans' => [], 'platforms' => ['vk'], 'activity' => 'any'], Campaigns::cleanSegment(['plans' => 'pro', 'platforms' => ['vk', 'B@D'], 'activity' => 'x']));
    }

    public function testEditingTestSendAndStartAreGuarded(): void
    {
        $boss = $this->staff(StaffRole::Content);
        self::assertSame(200, $this->get('/admin/campaigns')->status);
        self::assertSame(200, $this->get('/admin/campaigns/new')->status);
        $this->post('/admin/campaigns', ['name' => '', 'subject' => 'x', 'body' => 'y']);
        self::assertSame([], $this->db->select('SELECT 1 FROM email_campaigns'), 'a campaign needs a name, a subject and a text');

        $this->post('/admin/campaigns', ['name' => 'Осень', 'subject' => 'Новости', 'body' => "Привет, {name}!\n\n[Смотреть](https://example.com)", 'plans' => ['pro'], 'activity' => 'any']);
        $id = (string) $this->db->select('SELECT public_id FROM email_campaigns')[0]['public_id'];
        $page = $this->get('/admin/campaigns/' . $id);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Привет, Анна!', $page->body, 'the preview uses an example name');

        $this->post('/admin/campaigns/' . $id . '/test', []);
        $test = $this->mailer->lastTo((string) $boss->email);
        self::assertNotNull($test);
        self::assertSame('[Тест] Новости', $test->subject);
        self::assertStringContainsString('Отписаться', $test->html);
        self::assertArrayHasKey('List-Unsubscribe', $test->headers);
        self::assertSame('List-Unsubscribe=One-Click', $test->headers['List-Unsubscribe-Post']);
        self::assertStringContainsString('http://localhost/unsubscribe/' . $boss->id, $test->headers['List-Unsubscribe']);

        $this->person('reader@example.com');
        $this->post('/admin/campaigns/' . $id, ['name' => 'Осень 2', 'subject' => 'Новости', 'body' => 'Текст', 'activity' => 'any']);
        self::assertSame('Осень 2', $this->db->select('SELECT name FROM email_campaigns')[0]['name']);
        $this->post('/admin/campaigns/' . $id . '/start', []);
        self::assertSame('draft', $this->db->select('SELECT status FROM email_campaigns')[0]['status'], 'starting needs a fresh code');
        self::assertSame(403, (function (): int {
            $this->staff(StaffRole::Support);

            return $this->get('/admin/campaigns')->status;
        })());
    }

    public function testSendingIsThrottledSkipsPeopleWhoLeftAndCountsUnsubscribes(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[$n] = $this->person('reader' . $n . '@example.com');
        }
        $this->app->container()->get(Settings::class)->set('campaigns.per_minute', 2, null);
        $public = $this->campaign('Привет, {name}!');
        $campaigns = $this->app->container()->get(Campaigns::class);
        $started = $campaigns->start($public);
        self::assertSame(5, $started);
        self::assertSame(0, $campaigns->start($public), 'a started campaign cannot be started twice');
        $row = $campaigns->find($public);
        self::assertNotNull($row);
        $campaignId = (int) $row['id'];

        $this->drainQueue();
        self::assertSame(2, $campaigns->stats($campaignId)['sent'], 'the first minute sends two');
        // One of the people leaves before their turn.
        $this->app->container()->get(MarketingConsent::class)->unsubscribe($ids[3]);
        $this->clock->advance(61);
        $this->drainQueue();
        $this->clock->advance(61);
        $this->drainQueue();
        $stats = $campaigns->stats($campaignId);
        self::assertEquals(['sent' => 4, 'skipped' => 1, 'queued' => 0, 'failed' => 0], array_intersect_key($stats, array_flip(['sent', 'skipped', 'queued', 'failed'])));
        self::assertSame('done', $campaigns->find($public)['status'] ?? null);
        self::assertSame([], $this->mailer->to('reader3@example.com'));
        $mail = $this->mailer->lastTo('reader1@example.com');
        self::assertNotNull($mail);
        self::assertStringContainsString('Привет, Человек reader1@example.com!', $mail->text);
        self::assertStringContainsString('/unsubscribe/' . $ids[1] . '?c=' . $campaignId . '&signature=', $mail->headers['List-Unsubscribe']);

        // Unsubscribing from the link of the email: confirm page, then one click.
        $link = trim($mail->headers['List-Unsubscribe'], '<>');
        $path = (string) parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);
        $this->useBrowser();
        $page = $this->get($path);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Отписаться от новостей?', $page->body);
        self::assertTrue($this->app->container()->get(MarketingConsent::class)->isSubscribed($ids[1]), 'opening the link alone changes nothing');
        $done = $this->request('POST', $path, []);
        self::assertSame(200, $done->status);
        self::assertStringContainsString('Вы отписались', $done->body);
        self::assertFalse($this->app->container()->get(MarketingConsent::class)->isSubscribed($ids[1]));
        self::assertSame(1, $campaigns->stats($campaignId)['unsubscribed']);
        self::assertStringContainsString('Вы уже отписаны', $this->get($path)->body);

        // The one-click POST of mail programs (no CSRF token, no cookies).
        $mail2 = $this->mailer->lastTo('reader2@example.com');
        self::assertNotNull($mail2);
        $link2 = trim($mail2->headers['List-Unsubscribe'], '<>');
        $oneClick = $this->request('POST', (string) parse_url($link2, PHP_URL_PATH) . '?' . parse_url($link2, PHP_URL_QUERY), ['List-Unsubscribe' => 'One-Click']);
        self::assertSame('OK', $oneClick->body);
        self::assertFalse($this->app->container()->get(MarketingConsent::class)->isSubscribed($ids[2]));
        self::assertContains('marketing.unsubscribed', array_column($this->db->select('SELECT action FROM audit_log'), 'action'));
    }

    public function testUnsubscribeLinksCannotBeForged(): void
    {
        $a = $this->person('a@example.com');
        $b = $this->person('b@example.com');
        $consent = $this->app->container()->get(MarketingConsent::class);
        $linkA = $consent->link($a);
        $path = (string) parse_url($linkA, PHP_URL_PATH);
        $signature = (string) parse_url($linkA, PHP_URL_QUERY);
        $this->useBrowser();
        self::assertSame(404, $this->get('/unsubscribe/' . $b . '?' . $signature)->status, 'the signature of A does not work for B');
        self::assertSame(404, $this->request('POST', '/unsubscribe/' . $b . '?' . $signature, [])->status);
        self::assertSame(404, $this->get($path)->status, 'no signature');
        self::assertSame(404, $this->get($path . '?signature=abc')->status);
        self::assertSame(404, $this->get($path . '?c=5&' . $signature)->status, 'the campaign number is part of what is signed');
        self::assertTrue($consent->isSubscribed($b));
        self::assertSame(200, $this->get($path . '?' . $signature)->status);
    }

    public function testCancelStopsTheRestOfASending(): void
    {
        foreach (range(1, 3) as $n) {
            $this->person('c' . $n . '@example.com');
        }
        $this->app->container()->get(Settings::class)->set('campaigns.per_minute', 1, null);
        $public = $this->campaign();
        $campaigns = $this->app->container()->get(Campaigns::class);
        $campaigns->start($public);
        $this->drainQueue();
        $this->staff(StaffRole::Content);
        $this->post('/admin/campaigns/' . $public . '/cancel', []);
        $this->clock->advance(61);
        $this->drainQueue();
        $row = $campaigns->find($public);
        self::assertNotNull($row);
        self::assertSame('cancelled', $row['status']);
        self::assertSame(1, $campaigns->stats((int) $row['id'])['sent']);
    }

    // ---- support -------------------------------------------------------------------------------------------------

    public function testFeedbackFormOpensATicketWithContext(): void
    {
        [$user, $workspace] = $this->ownerWithWorkspace('writer@example.com', 'Автор');
        $this->actAs($user);
        $this->post('/feedback', ['message' => 'Пост не вышел в канал, помогите разобраться пожалуйста', 'from' => '/w/' . $workspace->publicId]);
        $ticket = $this->db->select('SELECT * FROM support_tickets')[0];
        self::assertSame('form', $ticket['source']);
        self::assertSame($user->id, (int) $ticket['user_id']);
        self::assertSame('open', $ticket['status']);
        $context = json_decode((string) $ticket['context_json'], true);
        self::assertArrayHasKey('plan', $context);
        self::assertSame('/w/' . $workspace->publicId, $context['page']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM support_messages')[0]['c']);
        $this->drainQueue();
        self::assertNotNull($this->mailer->lastTo($this->app->container()->get(\App\Kernel\Config::class)->string('mail.support')), 'the mailbox still gets a copy');
    }

    public function testStaffAnswerByEmailWithNotesAndStatus(): void
    {
        [$user] = $this->ownerWithWorkspace('asker@example.com', 'Спрашивающий');
        $tickets = $this->app->container()->get(Tickets::class);
        $public = $tickets->open($user, 'asker@example.com', 'Не приходит письмо', 'Письмо с подтверждением не пришло.', 'form', ['plan' => 'Про (active)']);
        $staff = $this->staff(StaffRole::Support);

        $list = $this->plain($this->get('/admin/support'));
        self::assertStringContainsString('Не приходит письмо', $list);
        $page = $this->plain($this->get('/admin/support/' . $public));
        self::assertStringContainsString('Письмо с подтверждением не пришло.', $page);
        self::assertStringContainsString('Про (active)', $page);
        self::assertStringContainsString('Спрашивающий', $page);

        $this->post('/admin/support/' . $public . '/reply', ['body' => 'Проверьте папку «Спам», мы отправили письмо ещё раз.', 'kind' => 'reply']);
        $this->drainQueue();
        $reply = $this->mailer->lastTo('asker@example.com');
        self::assertNotNull($reply);
        self::assertSame('Re: Не приходит письмо', $reply->subject);
        self::assertStringContainsString('Проверьте папку «Спам»', $reply->text);
        $ticket = $tickets->find($public);
        self::assertNotNull($ticket);
        self::assertSame('pending', $ticket['status']);
        self::assertSame($staff->name, $ticket['assignee_name'], 'the first answer assigns the ticket');

        $sentBefore = count($this->mailer->sent);
        $this->post('/admin/support/' . $public . '/reply', ['body' => 'Клиент уже был у нас в марте, проверить сессии', 'kind' => 'note']);
        $this->drainQueue();
        self::assertSame($sentBefore, count($this->mailer->sent), 'a note is never sent');
        self::assertStringContainsString('Внутренняя заметка', $this->plain($this->get('/admin/support/' . $public)));

        $this->post('/admin/support/' . $public, ['status' => 'solved', 'assignee' => 'none']);
        $solved = $tickets->find($public);
        self::assertNotNull($solved);
        self::assertSame(['solved', null], [$solved['status'], $solved['assignee_name']]);
        self::assertStringContainsString('Решён', $this->plain($this->get('/admin/support?status=solved')));
        self::assertStringNotContainsString('Не приходит письмо', $this->plain($this->get('/admin/support?status=open')));
        self::assertStringNotContainsString('Не приходит письмо', $this->plain($this->get('/admin/support?assignee=me:' . $staff->id)));
        self::assertStringContainsString('Не приходит письмо', $this->plain($this->get('/admin/support?q=%D0%BF%D0%B8%D1%81%D1%8C%D0%BC%D0%BE')));
        self::assertSame(404, $this->get('/admin/support/01JZZZZZZZZZZZZZZZZZZZZZZZ')->status);
        $this->post('/admin/support/' . $public . '/reply', ['body' => '', 'kind' => 'reply']);
        self::assertStringContainsString('Не приходит письмо', $this->plain($this->get('/admin/users/' . $user->id)), 'the person card lists their tickets');
    }

    public function testOnlyTheRightRolesTouchSupport(): void
    {
        $tickets = $this->app->container()->get(Tickets::class);
        $public = $tickets->open(null, 'anon@example.com', 'Вопрос', 'Текст', 'form');
        foreach ([StaffRole::Content, StaffRole::Finance, StaffRole::Analyst] as $role) {
            $this->staff($role);
            self::assertSame(403, $this->get('/admin/support')->status, $role->value);
            self::assertSame(403, $this->post('/admin/support/' . $public . '/reply', ['body' => 'x'])->status);
        }
        $this->staff(StaffRole::Support);
        self::assertSame(200, $this->get('/admin/support')->status);
        self::assertSame(1, $tickets->openCount());
    }

    public function testTelegramMessagesBecomeTicketsAndAnswersGoBack(): void
    {
        [$user] = $this->ownerWithWorkspace('tg@example.com', 'Телеграм');
        $this->db->execute('INSERT INTO telegram_links (user_id, chat_id, username, linked_at) VALUES (?, 4242, ?, ?)', [$user->id, 'tguser', DbTime::format($this->clock->now())]);
        $message = static fn (int $chat, string $text): array => ['update_id' => 1, 'message' => ['message_id' => 5, 'date' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'username' => 'tguser'], 'text' => $text]];

        $this->tg('sendMessage', 'send_message');
        $this->webhook($message(4242, 'Бот не публикует в канал'));
        $this->tg('sendMessage', 'send_message');
        $this->webhook($message(4242, 'Уже второй час жду'));
        $ticket = $this->db->select('SELECT * FROM support_tickets')[0];
        self::assertSame(['telegram', 4242, $user->id], [$ticket['source'], (int) $ticket['telegram_chat_id'], (int) $ticket['user_id']]);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM support_tickets')[0]['c'], 'the second message joins the open ticket');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM support_messages')[0]['c']);

        // A chat nobody linked is told how to link it, and no ticket appears.
        $this->tg('sendMessage', 'send_message');
        $this->webhook($message(9999, 'Помогите'));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM support_tickets')[0]['c']);
        // A command is not a message to support.
        $this->webhook($message(4242, '/whatever'));
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM support_messages')[0]['c']);

        // The answer goes to the chat the ticket came from.
        $this->staff(StaffRole::Support);
        $this->post('/admin/support/' . $ticket['public_id'] . '/reply', ['body' => 'Добавьте бота админом канала', 'kind' => 'reply']);
        $jobs = array_filter($this->db->select('SELECT payload_json FROM jobs'), static fn (array $r): bool => str_contains((string) $r['payload_json'], 'SendTelegramNotificationJob'));
        self::assertCount(1, $jobs);
        self::assertStringContainsString('4242', (string) array_values($jobs)[0]['payload_json']);
        self::assertSame([], $this->mailer->to('tg@example.com'));
    }
}
