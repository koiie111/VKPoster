<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelService;
use App\Domain\Channel\SharedBot;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Channels\MaxConnectController;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MaxConnectController::class)]
#[CoversClass(ChannelService::class)]
#[CoversClass(SharedBot::class)]
final class MaxConnectTest extends ChannelTestCase
{
    private const NEW_TOKEN = 'max-NEW-bot-token-not-real-0123456789abcdef';

    private function ownRequestFixtures(string $token = MaxFixtures::TOKEN, int $chat = MaxFixtures::CHANNEL, string $member = 'member_admin'): void
    {
        $this->max('GET', '/me', 'me');
        $this->max('GET', '/chats/mychannel', 'chat_channel');
        $this->max('GET', '/chats/' . $chat . '/members/me', $member);
    }

    public function testTheConnectPageExplainsTheSteps(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->channelsUrl($workspace, '/connect/max'));

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('Добавьте бота администратором канала', $text);
        self::assertStringContainsString('@ezposter_max_bot', $text);
        self::assertStringContainsString('Получить код', $text);
        self::assertStringContainsString('Свой бот', $text);
        self::assertStringNotContainsString('Telegram', $text);
        self::assertStringNotContainsString('data-testid="connect-code"', $page->body, 'no code before the owner asks for one');
    }

    public function testTheBotNameIsAskedFromMaxWhenNotConfiguredAndRemembered(): void
    {
        $this->app = TestEnv::app(['MAX_BOT_USERNAME' => '']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        $this->app->container()->get(\Redis::class)->del('shared-bot:max:username');
        [, $workspace] = $this->ownerSession();
        $this->max('GET', '/me', 'me');

        $first = $this->text($this->get($this->channelsUrl($workspace, '/connect/max')));
        $second = $this->text($this->get($this->channelsUrl($workspace, '/connect/max')));

        self::assertStringContainsString('@ezposter_bot', $first);
        self::assertStringContainsString('@ezposter_bot', $second, 'the second page needs no call to MAX');
        $this->http->assertAllConsumed();
    }

    public function testWithoutASharedBotThePageSaysSoAndNoCodeIsIssued(): void
    {
        $this->app = TestEnv::app(['MAX_BOT_TOKEN' => '']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [, $workspace] = $this->ownerSession();

        self::assertStringContainsString('Бот сервиса пока не настроен', $this->text($this->get($this->channelsUrl($workspace, '/connect/max'))));
        $this->post($this->channelsUrl($workspace, '/connect/max/code'));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channel_connect_codes')[0]['c']);
    }

    public function testAskingForACodeShowsItAndReloadingKeepsIt(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->post($this->channelsUrl($workspace, '/connect/max/code'));
        $first = $this->get($this->channelsUrl($workspace, '/connect/max'))->body;
        $second = $this->get($this->channelsUrl($workspace, '/connect/max'))->body;

        if (preg_match('/data-testid="connect-code">([A-Z2-9-]{11})</', $first, $m) !== 1) {
            self::fail('the page shows no connect code');
        }
        self::assertStringContainsString($m[1], $second);
        self::assertStringContainsString('/connect ' . str_replace('-', '', $m[1]), $first);
        self::assertSame('max', $this->db->select('SELECT platform FROM channel_connect_codes')[0]['platform']);
        self::assertSame(64, strlen((string) $this->db->select('SELECT code_hash FROM channel_connect_codes')[0]['code_hash']), 'only the hash is stored');
    }

    public function testAnExpiredCodeIsReplacedByTheButton(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->post($this->channelsUrl($workspace, '/connect/max/code'));
        $this->clock->advance(901);

        $page = $this->get($this->channelsUrl($workspace, '/connect/max'));

        self::assertStringNotContainsString('data-testid="connect-code"', $page->body);
        self::assertStringContainsString('Получить код', $this->text($page));
    }

    public function testTheStatusOfSomeoneElsesCodeIsNotFound(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->issueMaxCode($workspace, $owner);
        $publicId = (string) $this->db->select('SELECT public_id FROM channel_connect_codes')[0]['public_id'];
        $this->actAsMember($workspace, 'admin@example.com', Role::Admin);

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/max/status/' . $publicId))->status);
        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/max/status/01ARZ3NDEKTSV4RRFFQ69G5FAV'))->status);
    }

    public function testTheChannelListOffersMax(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->channelsUrl($workspace));

        self::assertStringContainsString('href="' . $this->channelsUrl($workspace, '/connect/max') . '"', $page->body);
    }

    public function testGuestsAreSentToTheLogin(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        self::assertSame(302, $this->get($this->channelsUrl($workspace, '/connect/max'))->status);
        self::assertStringContainsString('/login', (string) $this->get($this->channelsUrl($workspace, '/connect/max'))->header('Location'));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonManagers(): array
    {
        return ['editor' => [Role::Editor], 'author' => [Role::Author], 'viewer' => [Role::Viewer], 'client' => [Role::Client]];
    }

    #[DataProvider('nonManagers')]
    public function testOnlyOwnersAndAdministratorsMayConnect(Role $role): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->actAsMember($workspace, 'member@example.com', $role);

        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/max'))->status);
        self::assertSame(403, $this->post($this->channelsUrl($workspace, '/connect/max/code'))->status);
        self::assertSame(403, $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => 'x', 'reference' => 'y'])->status);
        self::assertSame([], $this->http->requests);
    }

    public function testAnotherWorkspaceCannotUseThePages(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        [$stranger] = $this->ownerWithWorkspace('other@example.com', 'Борис');
        $this->actAs($stranger);

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/max'))->status);
        self::assertSame(404, $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel'])->status);
    }

    public function testTheStateChangingRoutesNeedACsrfToken(): void
    {
        [, $workspace] = $this->ownerSession();

        self::assertSame(419, $this->request('POST', $this->channelsUrl($workspace, '/connect/max/code'))->status);
        self::assertSame(419, $this->request('POST', $this->channelsUrl($workspace, '/connect/max/own'))->status);
    }

    public function testMaxPagesDisappearWhenTheFeatureFlagIsOff(): void
    {
        $this->app = TestEnv::app(['PLATFORMS_ENABLED' => 'telegram']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [, $workspace] = $this->ownerSession();

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/max'))->status);
        self::assertSame(404, $this->post($this->channelsUrl($workspace, '/connect/max/code'))->status);
        self::assertSame(404, $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel'])->status);
        self::assertStringNotContainsString('/connect/max', $this->get($this->channelsUrl($workspace))->body);
    }

    public function testConnectingWithAnOwnBotStoresTheTokenEncryptedAndNeverShowsIt(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->ownRequestFixtures();

        $response = $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => ' ' . MaxFixtures::TOKEN . ' ', 'reference' => 'https://max.ru/mychannel']);

        self::assertSame(302, $response->status);
        self::assertSame($this->channelsUrl($workspace), $response->header('Location'));
        $credential = $this->db->select('SELECT * FROM platform_credentials')[0];
        self::assertSame('max', $credential['platform']);
        self::assertStringStartsWith('v1:', (string) $credential['secret_enc']);
        self::assertStringNotContainsString('own-bot-token', (string) $credential['secret_enc'], 'the raw column is ciphertext');
        self::assertStringNotContainsString('own-bot-token', (string) $credential['hint']);
        self::assertSame(MaxFixtures::TOKEN, $this->app->container()->get(\App\Kernel\Security\Crypto::class)->decrypt((string) $credential['secret_enc']));
        $channel = $this->db->select('SELECT platform, external_id, mode, credential_id, status FROM channels')[0];
        self::assertSame('max', $channel['platform']);
        self::assertSame('-72000000000001', $channel['external_id']);
        self::assertSame(ChannelMode::OwnBot->value, $channel['mode']);
        self::assertSame((int) $credential['id'], (int) $channel['credential_id']);
        self::assertSame('active', $channel['status']);
        foreach ($this->http->requests as $request) {
            self::assertSame(MaxFixtures::TOKEN, $request['options']['headers']['Authorization'], 'the customer token, never the shared one');
        }

        $page = $this->get($this->channelsUrl($workspace));
        self::assertStringContainsString('MAX', $this->text($page));
        self::assertStringContainsString('свой бот', $this->text($page));
        self::assertStringNotContainsString('own-bot-token', $page->body);
        self::assertStringNotContainsString('own-bot-token', (string) json_encode($this->db->select('SELECT * FROM audit_log')));
        self::assertContains('channel.connected', $this->auditActions($workspace));
    }

    public function testAWrongTokenIsExplainedAndNotEchoedBack(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->max('GET', '/me', 'error_401', 401);

        $response = $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel']);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/connect/max?tab=own', (string) $response->header('Location'));
        $page = $this->get((string) $response->header('Location'));
        self::assertStringContainsString('Токен бота MAX не подошёл', $this->text($page));
        self::assertStringNotContainsString('own-bot-token', $page->body, 'the token is not sent back to the form');
        self::assertStringContainsString('value="mychannel"', $page->body, 'what the person typed besides the token is kept');
        self::assertStringContainsString('id="connect-mode-panel-own"', $page->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAMalformedTokenNeverReachesMax(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => 'not a token', 'reference' => 'mychannel']);

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('выглядит неправильно', $this->text($this->get($this->channelsUrl($workspace, '/connect/max?tab=own'))));
    }

    public function testAnInviteLinkIsExplained(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'https://max.ru/join/abcdef123']);

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('Ссылка-приглашение не подойдёт', $this->text($this->get($this->channelsUrl($workspace, '/connect/max?tab=own'))));
    }

    public function testAnOwnBotWithoutRightsInTheChannelIsRefused(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->ownRequestFixtures(member: 'member_plain');

        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel']);

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM platform_credentials')[0]['c'], 'no token is kept for a connection that did not happen');
    }

    public function testReconnectingWithANewTokenReplacesTheOldOneInPlace(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->ownRequestFixtures();
        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel']);
        $this->ownRequestFixtures();

        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => self::NEW_TOKEN, 'reference' => 'mychannel']);

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $credentials = $this->db->select('SELECT secret_enc FROM platform_credentials');
        self::assertCount(1, $credentials);
        self::assertSame(self::NEW_TOKEN, $this->app->container()->get(\App\Kernel\Security\Crypto::class)->decrypt((string) $credentials[0]['secret_enc']));
    }

    public function testThePerWorkspaceLimitIsEnforcedForOwnBots(): void
    {
        $this->app = TestEnv::app(['CHANNELS_MAX' => '1']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [$owner, $workspace] = $this->ownerSession();
        $this->makeChannel($workspace, $owner, '-1009999999999', 'Другой');

        $this->post($this->channelsUrl($workspace, '/connect/max/own'), ['token' => MaxFixtures::TOKEN, 'reference' => 'mychannel']);

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('лимит', $this->text($this->get($this->channelsUrl($workspace, '/connect/max?tab=own'))));
    }
}
