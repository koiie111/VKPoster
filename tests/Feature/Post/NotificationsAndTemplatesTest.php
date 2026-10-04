<?php

declare(strict_types=1);

namespace App\Tests\Feature\Post;

use App\Domain\Notification\NotificationSettings;
use App\Domain\Notification\NotificationType;
use App\Domain\Notification\Notifier;
use App\Domain\Notification\SendTelegramNotificationJob;
use App\Domain\Notification\TelegramLinks;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Posts\TemplateController;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Kernel\Queue\Worker;
use App\Tests\Support\PostTestCase;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NotificationController::class)]
#[CoversClass(NotificationSettings::class)]
#[CoversClass(TelegramLinks::class)]
#[CoversClass(Notifier::class)]
#[CoversClass(SendTelegramNotificationJob::class)]
#[CoversClass(TemplateController::class)]
final class NotificationsAndTemplatesTest extends PostTestCase
{
    private function linkToken(): string
    {
        $page = $this->post('/account/notifications/telegram/link');
        self::assertSame(302, $page->status);
        $html = $this->get('/account/notifications')->body;
        self::assertMatchesRegularExpression('~https://t\.me/ezposter_bot\?start=([A-Za-z0-9_-]{20,64})~', $html);
        preg_match('~start=([A-Za-z0-9_-]{20,64})~', $html, $m);

        return $m[1];
    }

    private function startUpdate(string $token, int $chatId = 4242): array
    {
        return ['update_id' => 9001, 'message' => ['message_id' => 1, 'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Иван', 'username' => 'ivan'], 'chat' => ['id' => $chatId, 'type' => 'private'], 'date' => 1790000000, 'text' => '/start ' . $token]];
    }

    public function testTheSettingsPageStartsWithSensibleDefaultsAndSavesChoices(): void
    {
        [$owner] = $this->ownerSession();

        $page = $this->get('/account/notifications');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Пост не удалось опубликовать', $page->body);
        $defaults = $this->app->container()->get(NotificationSettings::class)->forUser($owner->id);
        self::assertTrue($defaults['publish_failed']['email']);
        self::assertTrue($defaults['channel_problem']['telegram']);
        self::assertFalse($defaults['publish_ok']['email'], 'success messages are off until asked for');

        $this->post('/account/notifications', ['n' => ['publish_failed' => ['email' => '0'], 'publish_ok' => ['email' => '1', 'telegram' => '1']]]);

        $saved = $this->app->container()->get(NotificationSettings::class)->forUser($owner->id);
        self::assertFalse($saved['publish_failed']['email']);
        self::assertFalse($saved['publish_failed']['telegram'], 'a box that was not sent is off');
        self::assertTrue($saved['publish_ok']['email']);
        self::assertTrue($saved['publish_ok']['telegram']);
    }

    public function testSettingsNeedASignedInUser(): void
    {
        $this->useBrowser();
        self::assertSame(302, $this->get('/account/notifications')->status);
    }

    public function testTelegramIsLinkedWithAOneTimeTokenThroughTheBot(): void
    {
        [$owner] = $this->ownerSession();
        $token = $this->linkToken();
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM telegram_link_tokens')[0]['n']);
        self::assertStringNotContainsString($token, json_encode($this->db->select('SELECT * FROM telegram_link_tokens'), JSON_THROW_ON_ERROR), 'only the hash is stored');

        $this->tg('sendMessage', 'send_message');
        self::assertSame(200, $this->webhook($this->startUpdate($token))->status);

        $link = $this->app->container()->get(TelegramLinks::class)->linkOf($owner->id);
        self::assertSame(['chat_id' => 4242, 'username' => 'ivan'], $link);
        self::assertStringContainsString('Готово', (string) $this->http->requests[0]['options']['json']['text']);
        self::assertStringContainsString('Отключить Telegram', $this->get('/account/notifications')->body);

        // The token works once.
        $this->tg('sendMessage', 'send_message');
        $this->webhook($this->startUpdate($token, 777));
        self::assertNull($this->app->container()->get(TelegramLinks::class)->linkOf($owner->id + 999));
        self::assertSame(4242, $this->app->container()->get(TelegramLinks::class)->linkOf($owner->id)['chat_id'] ?? null, 'a used token does not move the link');
    }

    public function testAnExpiredOrForgedTokenLinksNothing(): void
    {
        [$owner] = $this->ownerSession();
        $links = $this->app->container()->get(TelegramLinks::class);
        $token = $links->issueToken($owner->id);
        $this->clock->advance(901);

        self::assertNull($links->redeem($token, 1, null));
        self::assertNull($links->redeem(str_repeat('a', 32), 1, null));
        self::assertNull($links->redeem('short', 1, null));
        self::assertNull($links->linkOf($owner->id));
    }

    public function testUnlinkingTelegramRemovesTheChat(): void
    {
        [$owner] = $this->ownerSession();
        $links = $this->app->container()->get(TelegramLinks::class);
        $links->redeem($links->issueToken($owner->id), 55, null);
        self::assertNotNull($links->linkOf($owner->id));

        $this->post('/account/notifications/telegram/unlink');

        self::assertNull($links->linkOf($owner->id));
    }

    public function testAFailedPublicationIsAnnouncedByEmailAndTelegramToTheRightPeople(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $editor);
        $links = $this->app->container()->get(TelegramLinks::class);
        $links->redeem($links->issueToken($owner->id), 4242, 'olga');
        $this->app->container()->get(NotificationSettings::class)->save($editor->id, ['publish_failed' => ['email' => false, 'telegram' => false]]);
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute', 'Важный пост');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::Permanent, 'refused');
        $this->tg('sendMessage', 'send_message');

        $this->drain();
        $this->drainQueue();

        // The owner (administrator) is told by email, and in Telegram (linked); the author switched everything off; a viewer is never told.
        self::assertNotEmpty($this->mailer->to('owner@example.com'));
        self::assertStringContainsString('Не удалось опубликовать', $this->mailer->to('owner@example.com')[0]->subject);
        self::assertSame([], $this->mailer->to('editor@example.com'));
        self::assertSame([], $this->mailer->to('viewer@example.com'));
        self::assertNotNull($viewer);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM jobs')[0]['n'], 'the queue was drained');
        self::assertSame(4242, $this->http->requests[0]['options']['json']['chat_id'], 'the message went to the linked chat');
        self::assertStringContainsString('Важный пост', (string) $this->http->requests[0]['options']['json']['text']);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND sent_telegram = 1', [$owner->id])[0]['n']);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$editor->id])[0]['n']);
        self::assertSame('failed', $this->db->select('SELECT status FROM publications WHERE id = ?', [$publications[0]->id])[0]['status']);
    }

    public function testTheTelegramNotificationJobDoesNotThrowForABlockedBot(): void
    {
        $this->tg('sendMessage', 'error_403_kicked', 403);
        $job = new SendTelegramNotificationJob(4242, 'Привет');

        $this->app->container()->call([$job, 'handle']);

        self::assertCount(1, $this->http->requests);
    }

    public function testNotificationTypesHaveLabelsAndDefaults(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertNotSame('', $type->label());
            self::assertNotSame('', $type->hint());
            self::assertArrayHasKey('email', $type->defaults());
        }
    }

    public function testTemplatesAreSavedFromTheEditorAndUsedForNewPosts(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->fakeChannel($workspace, $owner);
        $base = $this->base($workspace);

        $saved = $this->post($base . '/templates', ['template_name' => 'Анонс', 'text' => 'Шаблонный **текст**', 'channels' => [$channel->publicId], 'silent' => '1']);
        self::assertSame(302, $saved->status);
        self::assertSame('Анонс', $this->db->select('SELECT name FROM post_templates')[0]['name']);
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM posts')[0]['n'], 'a template is not a post');

        $list = $this->get($base . '/templates');
        self::assertStringContainsString('Анонс', $list->body);
        $id = (string) $this->db->select('SELECT public_id FROM post_templates')[0]['public_id'];

        $editor = $this->get($base . '/posts/new?template=' . $id);
        self::assertSame(200, $editor->status);
        self::assertStringContainsString('Шаблонный **текст**', $editor->body);
        self::assertStringContainsString('checked', $editor->body);

        $this->post($base . '/templates/' . $id . '/delete');
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM post_templates')[0]['n']);
        self::assertSame(404, $this->get($base . '/posts/new?template=' . $id)->status);
    }

    public function testATemplateNeedsAName(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->fakeChannel($workspace, $owner);

        $this->post($this->base($workspace) . '/templates', ['template_name' => '   ', 'text' => 'x', 'channels' => [$channel->publicId]]);

        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM post_templates')[0]['n']);
    }

    public function testTemplatesBelongToTheirWorkspace(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace('a@example.com', 'Анна');
        $context = $this->contextFor($workspaceA, $ownerA);
        $id = $this->app->container()->get(\App\Domain\Post\PostTemplateRepository::class)->create($context, 'Секретный', $this->draft('Текст'));
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $this->actAs($ownerB);

        self::assertStringNotContainsString('Секретный', $this->get($this->base($workspaceB) . '/templates')->body);
        self::assertSame(404, $this->get($this->base($workspaceB) . '/posts/new?template=' . $id)->status);
        self::assertSame(404, $this->post($this->base($workspaceB) . '/templates/' . $id . '/delete')->status);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM post_templates')[0]['n']);
    }

    public function testOnlyEditorsOrTheAuthorDeleteTemplates(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $id = $this->app->container()->get(\App\Domain\Post\PostTemplateRepository::class)->create($context, 'Общий', $this->draft('Текст'));
        $this->actAs($this->memberOf($workspace, 'author@example.com', Role::Author));

        self::assertSame(403, $this->post($this->base($workspace) . '/templates/' . $id . '/delete')->status);
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM post_templates')[0]['n']);
        self::assertSame(200, $this->get($this->base($workspace) . '/templates')->status);
        self::assertNotNull($this->app->container()->get(Worker::class));
        self::assertNotSame('', TestEnv::WEBHOOK_SECRET);
        self::assertNotSame([], TelegramFixtures::update('update_private_start'));
    }
}
