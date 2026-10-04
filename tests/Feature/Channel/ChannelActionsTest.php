<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\ChannelAvatars;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelService;
use App\Http\Controllers\Channels\ChannelController;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChannelController::class)]
#[CoversClass(ChannelService::class)]
#[CoversClass(ChannelAvatars::class)]
final class ChannelActionsTest extends ChannelTestCase
{
    private const OWN_TOKEN = '555555555:OWN-bot-token-not-real-0123456789abc';
    private const OWN_BASE = 'https://api.telegram.org/bot' . self::OWN_TOKEN . '/';

    private function statusOf(int $id): string
    {
        return (string) $this->db->select('SELECT status FROM channels WHERE id = ?', [$id])[0]['status'];
    }

    public function testPauseAndResumeWithACheck(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $item = $this->channelsUrl($workspace, '/' . $channel->publicId);

        $this->post($item . '/pause');
        self::assertSame('paused', $this->statusOf($channel->id));
        self::assertStringContainsString('На паузе', $this->text($this->get($this->channelsUrl($workspace))));

        $this->expectChannelInspection();
        $this->post($item . '/resume');
        self::assertSame('active', $this->statusOf($channel->id));
        self::assertContains('channel.paused', $this->auditActions($workspace));
        self::assertContains('channel.resumed', $this->auditActions($workspace));
    }

    public function testResumingAChannelThatBrokeWhilePausedDoesNotPretendItWorks(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $item = $this->channelsUrl($workspace, '/' . $channel->publicId);
        $this->post($item . '/pause');

        $this->tg('getChat', 'get_chat_channel');
        $this->tg('getChatMember', 'member_bot_no_post');
        $this->post($item . '/resume');

        self::assertSame('error', $this->statusOf($channel->id));
        self::assertStringContainsString('Публикация сообщений', (string) $this->db->select('SELECT last_error FROM channels WHERE id = ?', [$channel->id])[0]['last_error']);
    }

    public function testCheckNowRecoversAChannelWhoseRightsCameBack(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $this->db->execute('UPDATE channels SET status = ?, last_error = ? WHERE id = ?', ['error', 'Не хватало прав', $channel->id]);
        $this->expectChannelInspection('get_chat_channel', 'member_bot_admin_limited');

        $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/check'));

        $row = $this->db->select('SELECT status, last_error, settings_json, last_health_at FROM channels WHERE id = ?', [$channel->id])[0];
        self::assertSame('active', $row['status']);
        self::assertNull($row['last_error']);
        self::assertFalse(json_decode((string) $row['settings_json'], true)['rights']['delete'], 'the new rights are stored');
        self::assertNotNull($row['last_health_at']);
    }

    public function testCheckNowDuringAnOutageKeepsTheStatus(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $this->tg('getChat', 'error_500', 502);

        $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/check'));

        self::assertSame('active', $this->statusOf($channel->id), 'a passing outage must not break the channel');
        $this->drainQueue();
        self::assertSame([], $this->mailer->sent);
    }

    public function testRenameAndResetTheName(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $item = $this->channelsUrl($workspace, '/' . $channel->publicId);

        $this->post($item . '/rename', ['name' => "  Клиент <b>А</b>\x00  "]);
        self::assertStringContainsString('Клиент &lt;b&gt;А&lt;/b&gt;', $this->get($this->channelsUrl($workspace))->body);

        $this->post($item . '/rename', ['name' => '']);
        $html = $this->get($this->channelsUrl($workspace))->body;
        self::assertStringContainsString('Мой канал', $html);
        self::assertStringNotContainsString('Клиент', $html);

        $this->post($item . '/rename', ['name' => str_repeat('я', 101)]);
        self::assertNull($this->db->select('SELECT alias FROM channels WHERE id = ?', [$channel->id])[0]['alias']);
    }

    public function testDisconnectingRemovesTheChannelItsAccessEntriesAndKeepsTheJournal(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $this->app->container()->get(\App\Domain\Workspace\ChannelAccessRepository::class)->set($this->contextFor($workspace, $owner), $owner->id, [$channel->id]);

        $response = $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/delete'));

        self::assertSame(302, $response->status);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM member_channel_access')[0]['c'], 'the access list follows the channel');
        self::assertContains('channel.disconnected', $this->auditActions($workspace));
    }

    public function testConnectingWithAnOwnBotStoresTheTokenEncryptedAndNeverShowsIt(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $this->tg('getMe', 'get_me', 200, self::OWN_TOKEN);
        $this->expectChannelInspection(token: self::OWN_TOKEN);

        $response = $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => ' ' . self::OWN_TOKEN . ' ', 'reference' => 'https://t.me/mychannel']);

        self::assertSame(302, $response->status);
        self::assertSame($this->channelsUrl($workspace), $response->header('Location'));
        $credential = $this->db->select('SELECT * FROM platform_credentials')[0];
        self::assertStringStartsWith('v1:', (string) $credential['secret_enc']);
        self::assertStringNotContainsString('OWN-bot-token', (string) $credential['secret_enc'], 'the raw column is ciphertext');
        self::assertSame('5555…9abc', $credential['hint']);
        self::assertStringNotContainsString('OWN-bot-token', $credential['hint']);
        self::assertSame(self::OWN_TOKEN, $this->app->container()->get(\App\Kernel\Security\Crypto::class)->decrypt((string) $credential['secret_enc']));
        $channel = $this->db->select('SELECT mode, credential_id, status FROM channels')[0];
        self::assertSame(ChannelMode::OwnBot->value, $channel['mode']);
        self::assertSame((int) $credential['id'], (int) $channel['credential_id']);
        self::assertSame('active', $channel['status']);

        $page = $this->get($this->channelsUrl($workspace));
        self::assertStringContainsString('свой бот', $this->text($page));
        self::assertStringNotContainsString('OWN-bot-token', $page->body);
        self::assertStringNotContainsString((string) $credential['secret_enc'], $page->body);
        self::assertStringNotContainsString('OWN-bot-token', (string) json_encode($this->db->select('SELECT * FROM audit_log')));
    }

    public function testAWrongTokenIsExplainedAndNotEchoedBack(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->tg('getMe', 'error_401', 401, self::OWN_TOKEN);

        $response = $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => self::OWN_TOKEN, 'reference' => '@mychannel']);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/connect/telegram?tab=own', (string) $response->header('Location'));
        $page = $this->get((string) $response->header('Location'));
        self::assertStringContainsString('Токен бота не подошёл', $this->text($page));
        self::assertStringNotContainsString('OWN-bot-token', $page->body, 'the token is not sent back to the form');
        self::assertStringContainsString('value="@mychannel"', $page->body, 'what the person typed besides the token is kept');
        self::assertStringContainsString('id="connect-mode-panel-own"', $page->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAMalformedTokenNeverReachesTelegram(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => 'not a token', 'reference' => '@mychannel']);

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('выглядит неправильно', $this->text($this->get($this->channelsUrl($workspace, '/connect/telegram?tab=own'))));
    }

    public function testAnOwnBotWithoutRightsInTheChannelIsRefused(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->tg('getMe', 'get_me', 200, self::OWN_TOKEN);
        $this->expectChannelInspection('get_chat_channel', 'member_plain', self::OWN_TOKEN);

        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => self::OWN_TOKEN, 'reference' => '@mychannel']);

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c'], 'no token is kept for a connection that did not happen');
    }

    public function testReconnectingWithANewTokenReplacesTheOldOneInPlace(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $this->tg('getMe', 'get_me', 200, self::OWN_TOKEN);
        $this->expectChannelInspection(token: self::OWN_TOKEN);
        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => self::OWN_TOKEN, 'reference' => '@mychannel']);
        $newToken = '555555555:NEW-bot-token-not-real-0123456789abcd';
        $this->tg('getMe', 'get_me', 200, $newToken);
        $this->expectChannelInspection(token: $newToken);

        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => $newToken, 'reference' => '@mychannel']);

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $secrets = $this->db->select('SELECT secret_enc FROM platform_credentials');
        self::assertCount(1, $secrets);
        self::assertSame($newToken, $this->app->container()->get(\App\Kernel\Security\Crypto::class)->decrypt((string) $secrets[0]['secret_enc']));
        unset($owner);
    }

    public function testDisconnectingAnOwnBotChannelDeletesItsToken(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->tg('getMe', 'get_me', 200, self::OWN_TOKEN);
        $this->expectChannelInspection(token: self::OWN_TOKEN);
        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => self::OWN_TOKEN, 'reference' => '@mychannel']);
        $publicId = (string) $this->db->select('SELECT public_id FROM channels')[0]['public_id'];

        $this->post($this->channelsUrl($workspace, '/' . $publicId . '/delete'));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c']);
    }

    public function testAnOwnBotChannelIsCheckedWithItsOwnToken(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $vault = $this->app->container()->get(\App\Domain\Channel\CredentialVault::class);
        $credentialId = $vault->store($this->contextFor($workspace, $owner), \App\Integrations\Social\Contracts\Platform::Telegram, 'bot_token', self::OWN_TOKEN);
        $channel = $this->makeChannel($workspace, $owner, mode: ChannelMode::OwnBot, credentialId: $credentialId);
        $this->tg('getChat', 'get_chat_channel', 200, self::OWN_TOKEN);
        $this->tg('getChatMember', 'member_bot_admin', 200, self::OWN_TOKEN);

        $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/check'));

        self::assertStringContainsString(self::OWN_BASE . 'getChat', $this->http->requests[0]['url']);
        self::assertSame(555555555, $this->http->requests[1]['options']['json']['user_id'], 'the bot id comes from the stored token');
        $this->http->assertAllConsumed();
    }

    public function testTheAvatarIsServedToMembersOnly(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);
        $key = 'avatars/' . $workspace->id . '/' . $channel->publicId . '.img';
        $this->storage->put($key, $this->stream(\App\Tests\Support\MediaFixtures::jpeg()));
        $this->db->execute('UPDATE channels SET avatar_key = ? WHERE id = ?', [$key, $channel->id]);
        $url = $this->channelsUrl($workspace, '/' . $channel->publicId . '/avatar');

        $response = $this->get($url);

        self::assertSame(200, $response->status);
        self::assertSame('image/jpeg', $response->header('Content-Type'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertStringContainsString('/avatar"', $this->get($this->channelsUrl($workspace))->body);

        $this->useBrowser();
        self::assertSame(302, $this->get($url)->status);
        $this->actAs($this->createUser('stranger@example.com'));
        self::assertSame(404, $this->get($url)->status);
    }

    public function testAChannelWithoutAvatarIs404ForTheImage(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/' . $channel->publicId . '/avatar'))->status);
    }

    public function testTheLimitStopsOwnBotConnectionsToo(): void
    {
        $this->app = TestEnv::app(['CHANNELS_MAX' => '1']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        $this->app->container()->instance(\App\Integrations\Storage\MediaStorage::class, $this->storage);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $this->makeChannel($workspace, $owner, '-1009999999999', 'Другой');

        $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => self::OWN_TOKEN, 'reference' => '@mychannel']);

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('лимит', $this->text($this->get($this->channelsUrl($workspace, '/connect/telegram?tab=own'))));
        unset($owner);
    }

    /**
     * @return resource
     */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    public function testRecordedFixturesAreValidJson(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/Fixtures/telegram/*.json');
        self::assertIsArray($files);
        foreach ($files as $file) {
            if (str_contains($file, 'error_500')) {
                continue; // a deliberate HTML gateway page
            }
            self::assertIsArray(json_decode((string) file_get_contents($file), true), basename($file));
        }
        self::assertSame('ezposter_bot', TelegramFixtures::update('get_me')['result']['username']);
    }
}
