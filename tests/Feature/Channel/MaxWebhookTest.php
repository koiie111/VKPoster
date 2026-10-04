<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\MaxUpdateHandler;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Webhooks\MaxWebhookController;
use App\Integrations\Social\Contracts\Platform;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MaxWebhookController::class)]
#[CoversClass(MaxUpdateHandler::class)]
final class MaxWebhookTest extends ChannelTestCase
{
    private const CHAT = '-72000000000001';

    public function testACallWithoutTheSecretHeaderIsForbidden(): void
    {
        $response = $this->request('POST', '/webhooks/max/' . TestEnv::MAX_WEBHOOK_SECRET, MaxFixtures::update('update_dialog_start'), ['Content-Type' => 'application/json']);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->http->requests, 'nothing was read or sent before authenticity was checked');
    }

    public function testAWrongHeaderOrWrongPathSecretIsForbidden(): void
    {
        $update = MaxFixtures::update('update_dialog_start');

        self::assertSame(403, $this->maxWebhook($update, ['X-Max-Bot-Api-Secret' => 'nope'])->status);
        self::assertSame(403, $this->maxWebhook($update, [], 'another-secret-0123456789')->status);
        self::assertSame(403, $this->maxWebhook($update, ['X-Max-Bot-Api-Secret' => \App\Integrations\Social\Telegram\TelegramWebhook::headerToken(TestEnv::WEBHOOK_SECRET)], TestEnv::WEBHOOK_SECRET)->status, 'the Telegram secret does not open the MAX endpoint');
        self::assertSame([], $this->http->requests);
    }

    public function testWithoutAConfiguredSecretTheEndpointDoesNotExist(): void
    {
        $this->app = TestEnv::app(['MAX_WEBHOOK_SECRET' => '']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);

        self::assertSame(404, $this->maxWebhook(MaxFixtures::update('update_dialog_start'))->status);
    }

    public function testAnAuthenticCallWithAnUselessUpdateIsAcknowledged(): void
    {
        self::assertSame(200, $this->maxWebhook(['update_type' => 'message_edited'])->status);
        self::assertSame(200, $this->maxWebhook(['update_type' => 'message_created', 'message' => 'garbage'])->status);
        self::assertSame(200, $this->maxWebhook(['update_type' => 'bot_removed'])->status);
        self::assertSame([], $this->http->requests);
    }

    public function testANonJsonBodyIsAcknowledgedAndIgnored(): void
    {
        $response = $this->request('POST', '/webhooks/max/' . TestEnv::MAX_WEBHOOK_SECRET, [], [
            'Content-Type' => 'text/plain',
            'X-Max-Bot-Api-Secret' => \App\Integrations\Social\Max\MaxWebhook::headerToken(TestEnv::MAX_WEBHOOK_SECRET),
        ]);

        self::assertSame(200, $response->status);
    }

    public function testTheCodeWrittenInAChannelConnectsItAndTheMessageIsDeleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueMaxCode($workspace, $owner);
        $this->http->expect('GET', MaxFixtures::API . '/chats/-72000000000001', 200, MaxFixtures::raw('chat_channel'));
        $this->http->expect('GET', MaxFixtures::API . '/chats/-72000000000001/members/me', 200, MaxFixtures::raw('member_admin'));
        $this->max('DELETE', '/messages', 'success_true');

        $response = $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(200, $response->status);
        $context = $this->contextFor($workspace, $owner);
        $channel = $this->app->container()->get(ChannelRepository::class)->findByExternal($context, Platform::Max, self::CHAT);
        self::assertNotNull($channel);
        self::assertSame('Мой канал', $channel->title);
        self::assertSame('mychannel', $channel->username);
        self::assertSame('shared_bot', $channel->mode->value);
        self::assertSame(ChannelStatus::Active, $channel->status);
        self::assertNull($channel->credentialId, 'the shared bot needs no stored token');
        self::assertTrue($channel->rights()['post']);
        $last = $this->http->requests[count($this->http->requests) - 1];
        self::assertSame('DELETE', $last['method']);
        self::assertSame(['message_id' => 'mid.100'], $last['options']['query'], 'the message with the code is deleted');
        self::assertSame(TestEnv::MAX_BOT_TOKEN, $last['options']['headers']['Authorization'], 'the shared bot token is used');
        self::assertContains('channel.connected', $this->auditActions($workspace));
        $this->http->assertAllConsumed();
    }

    public function testTheWaitingPageSeesTheChannelAppear(): void
    {
        [, $workspace] = $this->ownerSession();
        self::assertSame(302, $this->post($this->channelsUrl($workspace, '/connect/max/code'))->status);
        $html = $this->get($this->channelsUrl($workspace, '/connect/max'))->body;
        if (preg_match('/data-testid="connect-code">([A-Z2-9]{5})-([A-Z2-9]{5})</', $html, $m) !== 1 || preg_match('~data-status-url="([^"]+)"~', $html, $u) !== 1) {
            self::fail('the connect page shows no code or no status URL');
        }
        $statusUrl = html_entity_decode($u[1]);
        self::assertSame('waiting', json_decode($this->get($statusUrl)->body, true)['state']);

        $this->expectMaxInspection(MaxFixtures::CHANNEL, 'chat_channel_private');
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $m[1] . $m[2]]));

        $status = json_decode($this->get($statusUrl)->body, true);
        self::assertSame('connected', $status['state']);
        self::assertSame('Закрытый канал', $status['channel']);
        self::assertStringContainsString('Закрытый канал', $this->get($this->channelsUrl($workspace))->body);
    }

    public function testACodeWorksOnlyOnceAndATelegramCodeDoesNotWorkInMax(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $telegramCode = $this->issueCode($workspace, $owner);
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $telegramCode]));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c'], 'a code of another network is unknown here');

        $code = $this->issueMaxCode($workspace, $owner);
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAnExpiredCodeConnectsNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueMaxCode($workspace, $owner);
        $this->clock->advance(901);
        $this->max('DELETE', '/messages', 'success_true');

        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testABotWithoutPostingRightsDoesNotConnectAndTheCodeStaysUsable(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $code = $this->issueMaxCode($workspace, $owner);
        $this->expectMaxInspection(MaxFixtures::CHANNEL, 'chat_channel', 'member_plain');
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $row = $this->db->select('SELECT public_id, failure, used_at FROM channel_connect_codes')[0];
        self::assertNull($row['used_at']);
        self::assertStringContainsString('не может публиковать', (string) $row['failure']);
        $status = json_decode($this->get($this->channelsUrl($workspace, '/connect/max/status/' . $row['public_id']))->body, true);
        self::assertSame('problem', $status['state']);

        // The owner fixes the rights and writes the same code again.
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testInAGroupTheSenderMustBeAnAdministrator(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueMaxCode($workspace, $owner);
        $this->max('GET', '/chats/-72000000000002/members', 'members_plain');
        $this->max('DELETE', '/messages', 'success_true');

        $this->maxWebhook(MaxFixtures::update('update_connect_group', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c'], 'an ordinary member cannot connect the group');
        self::assertSame(['user_ids' => '42'], $this->http->requests[0]['options']['query']);

        $this->max('GET', '/chats/-72000000000002/members', 'members_admin');
        $this->expectMaxInspection(MaxFixtures::GROUP, 'chat_group', 'member_admin');
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_group', ['{{CODE}}' => $code]));

        self::assertSame('group', $this->db->select('SELECT kind FROM channels')[0]['kind']);
    }

    public function testASenderThatCannotBeFoundIsNotAnAdministrator(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueMaxCode($workspace, $owner);
        $this->max('GET', '/chats/-72000000000002/members', 'members_empty');
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_group', ['{{CODE}}' => $code]));

        $this->max('GET', '/chats/-72000000000002/members', 'error_500', 500);
        $this->max('DELETE', '/messages', 'success_true');
        $this->maxWebhook(MaxFixtures::update('update_connect_group', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAMemberWhoLostThePermissionCannotUseTheirCode(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $code = $this->issueMaxCode($workspace, $admin);
        $this->db->execute('UPDATE workspace_members SET role = ? WHERE user_id = ?', ['viewer', $admin->id]);
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');

        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertStringContainsString('больше нет права', (string) $this->db->select('SELECT failure FROM channel_connect_codes')[0]['failure']);
    }

    public function testReconnectingTheSameChatKeepsItsRow(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $existing = $this->makeChannel($workspace, $owner, self::CHAT, 'Мой канал', platform: Platform::Max);
        $this->db->execute('UPDATE channels SET status = ?, last_error = ? WHERE id = ?', ['revoked', 'Бота удалили', $existing->id]);
        $code = $this->issueMaxCode($workspace, $owner);
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');

        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        $rows = $this->db->select('SELECT id, status, last_error FROM channels');
        self::assertCount(1, $rows);
        self::assertSame($existing->id, (int) $rows[0]['id']);
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
        $code = $this->issueMaxCode($workspace, $owner);
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');

        $this->maxWebhook(MaxFixtures::update('update_connect_channel', ['{{CODE}}' => $code]));

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertStringContainsString('лимит', (string) $this->db->select('SELECT failure FROM channel_connect_codes')[0]['failure']);
    }

    public function testTheBotExplainsItselfInAPrivateChat(): void
    {
        $this->max('POST', '/messages', 'message_sent');

        $this->maxWebhook(MaxFixtures::update('update_dialog_start'));

        self::assertSame(['user_id' => 42], $this->http->requests[0]['options']['query']);
        self::assertStringContainsString('/connect', $this->http->requests[0]['options']['json']['text']);
    }

    public function testBeingRemovedMarksTheChannelAndTellsTheOwnerOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner, self::CHAT, 'Мой канал', platform: Platform::Max);
        $this->max('GET', '/chats/-72000000000001', 'chat_removed');

        $this->maxWebhook(MaxFixtures::update('update_bot_removed'));
        $this->drainQueue();

        $row = $this->db->select('SELECT status, last_error FROM channels WHERE id = ?', [$channel->id])[0];
        self::assertSame('revoked', $row['status']);
        self::assertStringContainsString('Бота убрали', (string) $row['last_error']);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('owner@example.com', $this->mailer->sent[0]->to);

        // A second signal while it is already broken does not send another email.
        $this->max('GET', '/chats/-72000000000001', 'chat_removed');
        $this->maxWebhook(MaxFixtures::update('update_bot_removed'));
        $this->drainQueue();
        self::assertCount(1, $this->mailer->sent);
    }

    public function testRemovalOfABotFromAChatOfAnotherNetworkTouchesNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner, self::CHAT, 'Telegram-канал');

        $this->maxWebhook(MaxFixtures::update('update_bot_removed'));

        self::assertSame([], $this->http->requests);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
    }

    public function testNoBotTokenMeansNothingIsHandled(): void
    {
        $this->app = TestEnv::app(['MAX_BOT_TOKEN' => '']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);

        self::assertSame(200, $this->maxWebhook(MaxFixtures::update('update_dialog_start'))->status);
        self::assertSame([], $this->http->requests);
    }
}
