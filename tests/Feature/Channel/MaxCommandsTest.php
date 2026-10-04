<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Integrations\Social\Max\MaxWebhook;
use App\Kernel\Console\Command\MaxPollCommand;
use App\Kernel\Console\Command\MaxWebhookCommand;
use App\Kernel\Console\Output;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MaxPollCommand::class)]
#[CoversClass(MaxWebhookCommand::class)]
final class MaxCommandsTest extends ChannelTestCase
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
        $code = $this->issueMaxCode($workspace, $owner);
        $this->max('GET', '/subscriptions', 'subscriptions');
        $this->max('DELETE', '/subscriptions', 'success_true');
        $this->max('GET', '/me', 'me');
        $update = MaxFixtures::raw('update_connect_channel', ['{{CODE}}' => $code]);
        $this->http->expect('GET', MaxFixtures::API . '/updates', 200, '{"updates":[' . $update . '],"marker":901}');
        $this->expectMaxInspection();
        $this->max('DELETE', '/messages', 'success_true');

        [$exit, $text] = $this->exec(MaxPollCommand::class, ['--once']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Polling as @ezposter_bot', $text);
        self::assertStringContainsString('Handled 1 update(s).', $text);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
        $poll = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['url'], '/updates')))[0];
        self::assertArrayNotHasKey('marker', $poll['options']['query']);
        self::assertSame('message_created,bot_removed', $poll['options']['query']['types']);
        self::assertSame(['url' => 'https://old.example/webhooks/max/oldsecretoldsecretoldsecret'], $this->http->requests[1]['options']['query']);
    }

    public function testPollingNeedsTheTokenAndRefusesProduction(): void
    {
        [$exit, $text] = $this->exec(MaxPollCommand::class, ['--once'], ['MAX_BOT_TOKEN' => '']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('MAX_BOT_TOKEN is not set', $text);

        [$exit, $text] = $this->exec(MaxPollCommand::class, ['--once'], [
            'APP_ENV' => 'production', 'APP_URL' => 'https://example.com', 'DEV_OAUTH_FAKE' => '0', 'PLATFORMS_ENABLED' => 'telegram',
        ]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('development tool', $text);
    }

    public function testPollingStopsOnABadToken(): void
    {
        $this->max('GET', '/subscriptions', 'error_401', 401);

        [$exit, $text] = $this->exec(MaxPollCommand::class, ['--once']);

        self::assertSame(1, $exit);
        self::assertStringNotContainsString(TestEnv::MAX_BOT_TOKEN, $text);
    }

    public function testPollingStopsWhenAnUpdateRequestFails(): void
    {
        $this->max('GET', '/subscriptions', 'subscriptions_empty');
        $this->max('GET', '/me', 'me');
        $this->max('GET', '/updates', 'error_500', 500);

        [$exit] = $this->exec(MaxPollCommand::class, ['--once']);

        self::assertSame(1, $exit);
    }

    public function testSettingTheWebhookReplacesOldSubscriptionsAndPrintsNoSecret(): void
    {
        $this->max('GET', '/subscriptions', 'subscriptions');
        $this->max('DELETE', '/subscriptions', 'success_true');
        $this->max('POST', '/subscriptions', 'success_true');

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['set', 'https://example.com/']);

        self::assertSame(0, $exit);
        $sent = $this->http->requests[2]['options']['json'];
        self::assertSame('https://example.com/webhooks/max/' . TestEnv::MAX_WEBHOOK_SECRET, $sent['url']);
        self::assertSame(MaxWebhook::headerToken(TestEnv::MAX_WEBHOOK_SECRET), $sent['secret']);
        self::assertSame(['message_created', 'bot_removed'], $sent['update_types']);
        self::assertStringNotContainsString(TestEnv::MAX_WEBHOOK_SECRET, $text);
        self::assertStringContainsString('https://example.com/webhooks/max/***', $text);
    }

    public function testTheWebhookNeedsAPublicHttpsAddressAndASecret(): void
    {
        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['set', 'http://localhost:8080']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('https://', $text);

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['set', 'https://example.com'], ['MAX_WEBHOOK_SECRET' => 'short']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('MAX_WEBHOOK_SECRET', $text);

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['info'], ['MAX_BOT_TOKEN' => '']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('MAX_BOT_TOKEN', $text);
        self::assertSame([], $this->http->requests);
    }

    public function testInfoHidesTheSecretInTheUrl(): void
    {
        $this->http->expect('GET', MaxFixtures::API . '/subscriptions', 200, json_encode(['subscriptions' => [
            ['url' => 'https://example.com/webhooks/max/' . TestEnv::MAX_WEBHOOK_SECRET, 'update_types' => ['message_created']],
        ]], JSON_THROW_ON_ERROR));

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['info']);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString(TestEnv::MAX_WEBHOOK_SECRET, $text);
        self::assertStringContainsString('https://example.com/webhooks/max/***', $text);
    }

    public function testInfoWithoutASubscription(): void
    {
        $this->max('GET', '/subscriptions', 'subscriptions_empty');

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['info']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('url: (none)', $text);
    }

    public function testDeletingTheWebhook(): void
    {
        $this->max('GET', '/subscriptions', 'subscriptions');
        $this->max('DELETE', '/subscriptions', 'success_true');

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['delete']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Webhook removed', $text);
    }

    public function testAnApiErrorIsReportedWithoutTheToken(): void
    {
        $this->max('GET', '/subscriptions', 'error_500', 500);

        [$exit, $text] = $this->exec(MaxWebhookCommand::class, ['info']);

        self::assertSame(1, $exit);
        self::assertStringNotContainsString(TestEnv::MAX_BOT_TOKEN, $text);
    }
}
