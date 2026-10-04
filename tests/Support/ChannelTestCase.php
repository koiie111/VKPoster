<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ConnectCodes;
use App\Domain\Workspace\Workspace;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Max\MaxWebhook;
use App\Integrations\Social\Telegram\TelegramWebhook;
use App\Kernel\Http\Response;
use App\Kernel\HttpClient\HttpClientInterface;

/**
 * Base class for channel tests: a mocked HTTP client (no test can reach Telegram), recorded Bot API answers, a way to
 * send updates to the webhook the way Telegram does, and shortcuts to connect channels.
 */
abstract class ChannelTestCase extends MediaTestCase
{
    protected MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->http);
    }

    /**
     * Expect one Bot API call of the shared bot and answer it with a recorded fixture.
     */
    protected function tg(string $method, string $fixture, int $status = 200, ?string $token = null): void
    {
        $this->http->expect('POST', 'https://api.telegram.org/bot' . ($token ?? TestEnv::SHARED_BOT_TOKEN) . '/' . $method, $status, TelegramFixtures::raw($fixture));
    }

    /**
     * The calls the bot makes to connect a public channel it is a full administrator of.
     */
    protected function expectChannelInspection(string $chat = 'get_chat_channel', string $member = 'member_bot_admin', ?string $token = null): void
    {
        $this->tg('getChat', $chat, 200, $token);
        $this->tg('getChatMember', $member, 200, $token);
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, string> $headers extra or replacement headers
     */
    protected function webhook(array $update, array $headers = [], ?string $secret = null): Response
    {
        $headers += ['Content-Type' => 'application/json', 'X-Telegram-Bot-Api-Secret-Token' => TelegramWebhook::headerToken(TestEnv::WEBHOOK_SECRET)];

        return $this->request('POST', '/webhooks/telegram/' . ($secret ?? TestEnv::WEBHOOK_SECRET), $update, $headers);
    }

    /**
     * Expect one call to the MAX API (path as in the documentation, e.g. `/messages`) and answer it with a recorded fixture.
     */
    protected function max(string $method, string $path, string $fixture, int $status = 200): void
    {
        $this->http->expect($method, MaxFixtures::API . $path, $status, MaxFixtures::raw($fixture));
    }

    /**
     * The calls the shared MAX bot makes to connect a public channel it administers.
     */
    protected function expectMaxInspection(int $chat = MaxFixtures::CHANNEL, string $chatFixture = 'chat_channel', string $member = 'member_admin'): void
    {
        $this->max('GET', '/chats/' . $chat, $chatFixture);
        $this->max('GET', '/chats/' . $chat . '/members/me', $member);
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, string> $headers extra or replacement headers
     */
    protected function maxWebhook(array $update, array $headers = [], ?string $secret = null): Response
    {
        $headers += ['Content-Type' => 'application/json', 'X-Max-Bot-Api-Secret' => MaxWebhook::headerToken(TestEnv::MAX_WEBHOOK_SECRET)];

        return $this->request('POST', '/webhooks/max/' . ($secret ?? TestEnv::MAX_WEBHOOK_SECRET), $update, $headers);
    }

    protected function issueMaxCode(Workspace $workspace, \App\Domain\User\User $user): string
    {
        return $this->app->container()->get(ConnectCodes::class)->issue($this->contextFor($workspace, $user), Platform::Max)->code;
    }

    /**
     * A fresh connect code of the person who owns the context (the plain value, as shown on the page).
     */
    protected function issueCode(Workspace $workspace, \App\Domain\User\User $user): string
    {
        return $this->app->container()->get(ConnectCodes::class)->issue($this->contextFor($workspace, $user), Platform::Telegram)->code;
    }

    /**
     * Put a channel straight into the database (without going through Telegram).
     *
     * @param array<string, mixed> $settings
     */
    protected function makeChannel(Workspace $workspace, \App\Domain\User\User $by, string $externalId = '-1001234567890', string $title = 'Мой канал', ChannelMode $mode = ChannelMode::SharedBot, ?int $credentialId = null, array $settings = ['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]], Platform $platform = Platform::Telegram): Channel
    {
        $repository = $this->app->container()->get(ChannelRepository::class);

        return $repository->connect($this->contextFor($workspace, $by), $platform, $externalId, $mode, $title, 'mychannel', 'channel', $credentialId, $settings, $by->id)['channel'];
    }

    protected function channelsUrl(Workspace $workspace, string $suffix = ''): string
    {
        return $this->base($workspace) . '/channels' . $suffix;
    }
}
