<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\ConnectCodeRedeemer;
use App\Domain\Channel\TelegramUpdateHandler;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Integrations\Social\Contracts\Platform;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TelegramWebhookController::class)]
#[CoversClass(TelegramUpdateHandler::class)]
#[CoversClass(ConnectCodeRedeemer::class)]
final class TelegramWebhookTest extends ChannelTestCase
{
    public function testACallWithoutTheSecretHeaderIsForbidden(): void
    {
        $response = $this->request('POST', '/webhooks/telegram/' . TestEnv::WEBHOOK_SECRET, TelegramFixtures::update('update_private_start'), ['Content-Type' => 'application/json']);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->http->requests, 'nothing was read or sent before authenticity was checked');
    }

    public function testAWrongHeaderOrWrongPathSecretIsForbidden(): void
    {
        $update = TelegramFixtures::update('update_private_start');

        self::assertSame(403, $this->webhook($update, ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->status);
        self::assertSame(403, $this->webhook($update, [], 'another-secret-0123456789')->status);
        self::assertSame([], $this->http->requests);
    }

    public function testWithoutAConfiguredSecretTheEndpointDoesNotExist(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['TELEGRAM_WEBHOOK_SECRET' => '']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);

        self::assertSame(404, $this->webhook(TelegramFixtures::update('update_private_start'))->status);
    }

    public function testAnAuthenticCallWithAnUselessUpdateIsAcknowledged(): void
    {
        $response = $this->webhook(['update_id' => 1, 'edited_message' => ['text' => 'x']]);

        self::assertSame(200, $response->status);
        self::assertSame([], $this->http->requests);
    }

    public function testTheCodeWrittenInAChannelConnectsItAndTheMessageIsDeleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueCode($workspace, $owner);
        $this->expectChannelInspection();
        $this->tg('getFile', 'get_file');
        $this->http->expect('GET', 'https://api.telegram.org/file/bot' . TestEnv::SHARED_BOT_TOKEN . '/photos/file_1.jpg', 200, MediaFixtures::jpeg());
        $this->tg('deleteMessage', 'ok_true');

        $response = $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        self::assertSame(200, $response->status);
        $context = $this->contextFor($workspace, $owner);
        $channel = $this->app->container()->get(ChannelRepository::class)->findByExternal($context, Platform::Telegram, '-1001234567890');
        self::assertNotNull($channel);
        self::assertSame('Мой канал', $channel->title);
        self::assertSame('mychannel', $channel->username);
        self::assertSame('shared_bot', $channel->mode->value);
        self::assertSame(ChannelStatus::Active, $channel->status);
        self::assertNull($channel->credentialId, 'the shared bot needs no stored token');
        self::assertTrue($channel->rights()['post']);
        self::assertNotNull($channel->avatarKey);
        self::assertTrue($this->storage->exists($channel->avatarKey));
        self::assertSame(501, $this->http->requests[count($this->http->requests) - 1]['options']['json']['message_id'], 'the message with the code is deleted');
        self::assertContains('channel.connected', $this->auditActions($workspace));
        $this->http->assertAllConsumed();
    }

    public function testTheWaitingPageSeesTheChannelAppear(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $page = $this->post($this->channelsUrl($workspace, '/connect/telegram/code'));
        self::assertSame(302, $page->status);
        $html = $this->get($this->channelsUrl($workspace, '/connect/telegram'))->body;
        if (preg_match('/data-testid="connect-code">([A-Z2-9]{5})-([A-Z2-9]{5})</', $html, $m) !== 1 || preg_match('~data-status-url="([^"]+)"~', $html, $u) !== 1) {
            self::fail('the connect page shows no code or no status URL');
        }
        $statusUrl = html_entity_decode($u[1]);
        self::assertSame('waiting', json_decode($this->get($statusUrl)->body, true)['state']);

        $this->expectChannelInspection('get_chat_channel_private');
        $this->tg('deleteMessage', 'ok_true');
        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $m[1] . $m[2]]));

        $status = json_decode($this->get($statusUrl)->body, true);
        self::assertSame('connected', $status['state']);
        self::assertSame('Закрытый клуб', $status['channel']);
        self::assertStringContainsString('Закрытый клуб', $this->get($this->channelsUrl($workspace))->body);
    }

    public function testACodeWorksOnlyOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueCode($workspace, $owner);
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');
        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        // The same code in another chat: unknown now, so only the message is cleaned up.
        $this->tg('deleteMessage', 'ok_true');
        $second = TelegramFixtures::update('update_connect_group_message', ['{{CODE}}' => $code]);
        $this->webhook($second);

        $count = $this->db->select('SELECT COUNT(*) AS c FROM channels WHERE workspace_id = ?', [$workspace->id])[0]['c'];
        self::assertSame(1, (int) $count);
    }

    public function testAnExpiredCodeConnectsNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueCode($workspace, $owner);
        $this->clock->advance(901);
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAWrongCodeConnectsNothing(): void
    {
        $this->ownerWithWorkspace();
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => 'AAAAAAAAAA']));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testABotWithoutPostingRightsDoesNotConnectAndTheCodeStaysUsable(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $code = $this->issueCode($workspace, $owner);
        $this->expectChannelInspection('get_chat_channel', 'member_bot_no_post');
        $this->tg('deleteMessage', 'ok_true');
        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $row = $this->db->select('SELECT public_id, failure, used_at FROM channel_connect_codes')[0];
        self::assertNull($row['used_at']);
        self::assertStringContainsString('Публикация сообщений', (string) $row['failure']);
        $status = json_decode($this->get($this->channelsUrl($workspace, '/connect/telegram/status/' . $row['public_id']))->body, true);
        self::assertSame('problem', $status['state']);
        self::assertStringContainsString('Публикация сообщений', $status['message']);

        // The owner fixes the rights and writes the same code again.
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');
        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testInAGroupTheSenderMustBeAnAdministrator(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueCode($workspace, $owner);
        $this->tg('getChatMember', 'member_user_plain');
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_group_message', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c'], 'an ordinary member cannot connect the group');

        $this->tg('getChatMember', 'member_user_creator');
        $this->tg('getChat', 'get_chat_group');
        $this->tg('getChatMember', 'member_bot_group_admin');
        $this->tg('deleteMessage', 'ok_true');
        $this->webhook(TelegramFixtures::update('update_connect_group_message', ['{{CODE}}' => $code]));

        $channel = $this->db->select('SELECT kind, title FROM channels')[0];
        self::assertSame('group', $channel['kind']);
    }

    public function testAMemberWhoLostThePermissionCannotUseTheirCode(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $code = $this->issueCode($workspace, $admin);
        $this->workspaces->addMember($workspace->id, $admin->id, Role::Viewer, $owner->id);
        $this->db->execute('UPDATE workspace_members SET role = ? WHERE user_id = ?', ['viewer', $admin->id]);
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertStringContainsString('больше нет права', (string) $this->db->select('SELECT failure FROM channel_connect_codes')[0]['failure']);
    }

    public function testReconnectingTheSameChatKeepsItsRow(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $existing = $this->makeChannel($workspace, $owner);
        $this->db->execute('UPDATE channels SET status = ?, last_error = ? WHERE id = ?', ['revoked', 'Бота удалили', $existing->id]);
        $code = $this->issueCode($workspace, $owner);
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        $rows = $this->db->select('SELECT id, status, last_error FROM channels');
        self::assertCount(1, $rows);
        self::assertSame($existing->id, (int) $rows[0]['id'], 'scheduled posts and access lists stay attached');
        self::assertSame('active', $rows[0]['status']);
        self::assertNull($rows[0]['last_error']);
    }

    public function testThePerWorkspaceLimitIsEnforced(): void
    {
        $this->app = TestEnv::app(['CHANNELS_MAX' => '1']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->makeChannel($workspace, $owner, '-1009999999999', 'Другой');
        $code = $this->issueCode($workspace, $owner);
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');

        $this->webhook(TelegramFixtures::update('update_connect_channel_post', ['{{CODE}}' => $code]));

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertStringContainsString('лимит', (string) $this->db->select('SELECT failure FROM channel_connect_codes')[0]['failure']);
    }

    public function testTheBotExplainsItselfInAPrivateChat(): void
    {
        $this->tg('sendMessage', 'send_message');

        $this->webhook(TelegramFixtures::update('update_private_start'));

        self::assertStringContainsString('/connect', $this->http->requests[0]['options']['json']['text']);
    }

    public function testBeingKickedMarksTheChannelAndTellsTheOwnerOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $this->tg('getChat', 'error_403_kicked', 403);

        $this->webhook(TelegramFixtures::update('update_bot_kicked'));
        $this->drainQueue();

        $row = $this->db->select('SELECT status, last_error FROM channels WHERE id = ?', [$channel->id])[0];
        self::assertSame('revoked', $row['status']);
        self::assertStringContainsString('Бот больше не может писать', (string) $row['last_error']);
        $mails = $this->mailer->sent;
        self::assertCount(1, $mails);
        self::assertSame('owner@example.com', $mails[0]->to);
        self::assertStringContainsString('Мой канал', $mails[0]->subject);

        // A second signal while it is already broken does not send another email.
        $fresh = $this->app->container()->get(\App\Domain\Channel\ChannelSystem::class)->find($channel->id);
        self::assertNotNull($fresh);
        $this->tg('getChat', 'error_403_kicked', 403);
        $this->webhook(TelegramFixtures::update('update_bot_kicked'));
        $this->drainQueue();
        self::assertCount(1, $this->mailer->sent);
    }

    public function testANonJsonBodyIsAcknowledgedAndIgnored(): void
    {
        $response = $this->request('POST', '/webhooks/telegram/' . TestEnv::WEBHOOK_SECRET, [], [
            'Content-Type' => 'text/plain',
            'X-Telegram-Bot-Api-Secret-Token' => \App\Integrations\Social\Telegram\TelegramWebhook::headerToken(TestEnv::WEBHOOK_SECRET),
        ]);

        self::assertSame(200, $response->status);
    }
}
