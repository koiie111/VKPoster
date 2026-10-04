<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Integrations\Social\Telegram\TelegramWebhook;
use App\Kernel\Console\Command\ChannelsCheckCommand;
use App\Kernel\Console\Command\TelegramPollCommand;
use App\Kernel\Console\Command\TelegramWebhookCommand;
use App\Kernel\Console\Output;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TelegramPollCommand::class)]
#[CoversClass(TelegramWebhookCommand::class)]
#[CoversClass(ChannelsCheckCommand::class)]
final class TelegramCommandsTest extends ChannelTestCase
{
    /**
     * @param class-string<\App\Kernel\Console\Command> $class
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function exec(string $class, array $args = [], array $env = []): array
    {
        $app = TestEnv::app($env);
        $app->container()->instance(HttpClientInterface::class, $this->http);
        $app->container()->instance(Clock::class, $this->clock);
        $out = new Output();
        $code = $app->container()->get($class)->run($args, $out);

        return [$code, $out->contents()];
    }

    public function testPollingHandlesTheUpdatesItGets(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->issueCode($workspace, $owner);
        $this->tg('deleteWebhook', 'ok_true');
        $this->tg('getMe', 'get_me');
        $update = TelegramFixtures::raw('update_connect_channel_post', ['{{CODE}}' => $code]);
        $this->http->expect('POST', 'https://api.telegram.org/bot' . TestEnv::SHARED_BOT_TOKEN . '/getUpdates', 200, '{"ok":true,"result":[' . $update . ']}');
        $this->expectChannelInspection();
        $this->tg('deleteMessage', 'ok_true');

        [$exit, $text] = $this->exec(TelegramPollCommand::class, ['--once']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Polling as @ezposter_bot', $text);
        self::assertStringContainsString('Handled 1 update(s).', $text);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $poll = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/getUpdates')))[0];
        self::assertSame(0, $poll['options']['json']['offset']);
        self::assertSame(['message', 'channel_post', 'my_chat_member'], $poll['options']['json']['allowed_updates']);
    }

    public function testPollingNeedsTheTokenAndRefusesProduction(): void
    {
        [$exit, $text] = $this->exec(TelegramPollCommand::class, ['--once'], ['TELEGRAM_BOT_TOKEN' => '']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('TELEGRAM_BOT_TOKEN is not set', $text);

        [$exit, $text] = $this->exec(TelegramPollCommand::class, ['--once'], [
            'APP_ENV' => 'production', 'APP_URL' => 'https://example.com', 'DEV_OAUTH_FAKE' => '0', 'PLATFORMS_ENABLED' => 'telegram',
        ]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('development tool', $text);
    }

    public function testPollingStopsOnABadToken(): void
    {
        $this->tg('deleteWebhook', 'error_401', 401);

        [$exit, $text] = $this->exec(TelegramPollCommand::class, ['--once']);

        self::assertSame(1, $exit);
        self::assertStringNotContainsString('SHARED-bot-token', $text);
    }

    public function testSettingTheWebhookSendsBothSecretsAndPrintsNeitherOfThem(): void
    {
        $this->tg('setWebhook', 'ok_true');

        [$exit, $text] = $this->exec(TelegramWebhookCommand::class, ['set', 'https://example.com/']);

        self::assertSame(0, $exit);
        $sent = $this->http->requests[0]['options']['json'];
        self::assertSame('https://example.com/webhooks/telegram/' . TestEnv::WEBHOOK_SECRET, $sent['url']);
        self::assertSame(TelegramWebhook::headerToken(TestEnv::WEBHOOK_SECRET), $sent['secret_token']);
        self::assertSame(['message', 'channel_post', 'my_chat_member'], $sent['allowed_updates']);
        self::assertStringNotContainsString(TestEnv::WEBHOOK_SECRET, $text);
        self::assertStringContainsString('https://example.com/webhooks/telegram/***', $text);
    }

    public function testTheWebhookNeedsAPublicHttpsAddressAndASecret(): void
    {
        [$exit, $text] = $this->exec(TelegramWebhookCommand::class, ['set', 'http://localhost:8080']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('https://', $text);

        [$exit, $text] = $this->exec(TelegramWebhookCommand::class, ['set', 'https://example.com'], ['TELEGRAM_WEBHOOK_SECRET' => 'short']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('TELEGRAM_WEBHOOK_SECRET', $text);
        self::assertSame([], $this->http->requests);
    }

    public function testInfoHidesTheSecretInTheUrl(): void
    {
        $this->http->expect('POST', 'https://api.telegram.org/bot' . TestEnv::SHARED_BOT_TOKEN . '/getWebhookInfo', 200, json_encode(['ok' => true, 'result' => [
            'url' => 'https://example.com/webhooks/telegram/' . TestEnv::WEBHOOK_SECRET,
            'pending_update_count' => 3,
            'last_error_message' => 'Wrong response from the webhook: 403 Forbidden',
        ]], JSON_THROW_ON_ERROR));

        [$exit, $text] = $this->exec(TelegramWebhookCommand::class, ['info']);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString(TestEnv::WEBHOOK_SECRET, $text);
        self::assertStringContainsString('pending updates: 3', $text);
        self::assertStringContainsString('403 Forbidden', $text);
    }

    public function testDeletingTheWebhook(): void
    {
        $this->tg('deleteWebhook', 'ok_true');

        [$exit, $text] = $this->exec(TelegramWebhookCommand::class, ['delete']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Webhook removed', $text);
    }

    public function testChannelsCheckQueuesDueChannels(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->makeChannel($workspace, $owner);
        $this->db->execute('UPDATE channels SET last_health_at = NULL');

        [$exit, $text] = $this->exec(ChannelsCheckCommand::class);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Queued 1 channel check(s).', $text);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM jobs')[0]['c']);
    }

    public function testTheSchedulerRunsTheHealthCheckHourly(): void
    {
        $tasks = $this->app->container()->get(\App\Kernel\Queue\Schedule::class)->tasks();
        $names = array_map(static fn (\App\Kernel\Queue\ScheduledTask $t): string => $t->name, $tasks);

        self::assertContains('channels-health', $names);
    }
}
