<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Max\MaxClient;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxRateGate;
use App\Integrations\Social\Max\MaxWebhook;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\MockHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxClient::class)]
#[CoversClass(MaxClientFactory::class)]
#[CoversClass(MaxRateGate::class)]
#[CoversClass(MaxWebhook::class)]
final class MaxClientTest extends TestCase
{
    private MockHttpClient $http;
    private MaxClient $client;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->client = (new MaxClientFactory($this->http))->make(MaxFixtures::TOKEN);
    }

    public function testTheTokenTravelsInTheAuthorizationHeaderOnly(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/messages', 200, MaxFixtures::raw('message_sent'));

        $message = $this->client->sendMessage(-5, ['text' => 'hi'], true);

        self::assertSame('mid.0000000000000001', $message['body']['mid']);
        $request = $this->http->requests[0];
        self::assertSame(MaxFixtures::TOKEN, $request['options']['headers']['Authorization'], 'the raw token, without "Bearer"');
        self::assertStringNotContainsString(MaxFixtures::TOKEN, $request['url']);
        self::assertSame(['chat_id' => -5, 'disable_link_preview' => 'true'], $request['options']['query']);
        self::assertSame(['text' => 'hi'], $request['options']['json']);
    }

    public function testTheFactoryUsesTheConfiguredBaseAddress(): void
    {
        $client = (new MaxClientFactory($this->http, 'https://max.test'))->make('t');
        $this->http->expect('GET', 'https://max.test/me', 200, MaxFixtures::raw('me'));

        self::assertSame('ezposter_bot', $client->getMe()['username']);
    }

    public function testReadingMethodsHitTheirPaths(): void
    {
        $this->http->expect('GET', MaxFixtures::API . '/chats/-72000000000001', 200, MaxFixtures::raw('chat_channel'));
        $this->http->expect('GET', MaxFixtures::API . '/chats/mychannel', 200, MaxFixtures::raw('chat_channel'));
        $this->http->expect('GET', MaxFixtures::API . '/chats/%40mychannel', 200, MaxFixtures::raw('chat_channel'));
        $this->http->expect('GET', MaxFixtures::API . '/chats/-72000000000001/members/me', 200, MaxFixtures::raw('member_admin'));
        $this->http->expect('GET', MaxFixtures::API . '/chats/-72000000000001/members', 200, MaxFixtures::raw('members_admin'));

        $this->client->getChat(MaxFixtures::CHANNEL);
        $this->client->getChatByLink('mychannel');
        $this->client->getChatByLink('@mychannel');
        self::assertTrue($this->client->getMembership(MaxFixtures::CHANNEL)['is_admin']);
        $members = $this->client->getMembers(MaxFixtures::CHANNEL, [42, 43]);

        self::assertSame(42, $members[0]['user_id']);
        self::assertSame(['user_ids' => '42,43'], $this->http->requests[4]['options']['query']);
    }

    public function testEditDeletePinAndUnpin(): void
    {
        foreach (['PUT /messages', 'DELETE /messages', 'PUT /chats/-5/pin', 'DELETE /chats/-5/pin'] as $call) {
            [$method, $path] = explode(' ', $call);
            $this->http->expect($method, MaxFixtures::API . $path, 200, MaxFixtures::raw('success_true'));
        }

        $this->client->editMessage('mid.1', ['text' => 'new']);
        $this->client->deleteMessage('mid.1');
        $this->client->pinMessage(-5, 'mid.1');
        $this->client->unpinMessage(-5);

        self::assertSame(['message_id' => 'mid.1'], $this->http->requests[0]['options']['query']);
        self::assertSame(['message_id' => 'mid.1'], $this->http->requests[1]['options']['query']);
        self::assertSame(['message_id' => 'mid.1', 'notify' => false], $this->http->requests[2]['options']['json']);
    }

    public function testSuccessFalseWithStatus200IsAFailure(): void
    {
        $this->http->expect('DELETE', MaxFixtures::API . '/messages', 200, MaxFixtures::raw('success_false'));

        try {
            $this->client->deleteMessage('mid.1');
            self::fail('expected a PlatformError');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
            self::assertStringContainsString('message.not.found', $e->getMessage());
            self::assertStringNotContainsString('message.not.found', $e->forUser());
        }
    }

    public function testDirectMessageGoesToTheUser(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/messages', 200, MaxFixtures::raw('message_sent'));

        $this->client->sendDirect(42, 'hello');

        self::assertSame(['user_id' => 42], $this->http->requests[0]['options']['query']);
    }

    public function testUploadSlotAndFile(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/uploads', 200, MaxFixtures::raw('upload_slot_video'));
        $path = (string) tempnam(sys_get_temp_dir(), 'max');
        file_put_contents($path, 'bytes');
        $this->http->expect('POST', 'https://upload.max.example/upload?type=video&id=2', 200, MaxFixtures::raw('upload_video_done'));

        $slot = $this->client->uploadSlot('video');
        $answer = $this->client->uploadFile($slot['url'], $path, 'v.mp4');
        unlink($path);

        self::assertSame('video-token-abc', $slot['token']);
        self::assertSame(['type' => 'video'], $this->http->requests[0]['options']['query']);
        self::assertSame('1', $answer['retval']);
        $upload = $this->http->requests[1]['options'];
        self::assertSame('data', $upload['multipart'][0]['name']);
        self::assertArrayNotHasKey('headers', $upload, 'the bot token never goes to the file server');
    }

    public function testAnUploadSlotWithoutAnAddressIsRefused(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/uploads', 200, MaxFixtures::raw('upload_slot_no_url'));

        $this->expectException(PlatformError::class);
        $this->client->uploadSlot('image');
    }

    public function testAnUploadSlotWithAPlainHttpAddressIsRefused(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/uploads', 200, '{"url":"http://evil.example/up"}');

        $this->expectException(PlatformError::class);
        $this->client->uploadSlot('image');
    }

    public function testAnUnreadableFileIsAPermanentError(): void
    {
        try {
            $this->client->uploadFile('https://upload.max.example/x', '/nonexistent/file.bin', 'f.bin');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
        }
    }

    /**
     * @return iterable<string, array{int, string, ErrorKind, ?int}>
     */
    public static function errors(): iterable
    {
        yield 'bad token' => [401, 'error_401', ErrorKind::Auth, null];
        yield 'forbidden' => [403, 'error_403', ErrorKind::Auth, null];
        yield 'chat not found' => [404, 'error_404_chat', ErrorKind::Permanent, null];
        yield 'rate limit' => [429, 'error_429', ErrorKind::RateLimited, 2];
        yield 'server' => [500, 'error_500', ErrorKind::Temporary, null];
        yield 'not ready' => [400, 'error_400_not_ready', ErrorKind::Temporary, 3];
        yield 'bad request' => [400, 'error_400_bad', ErrorKind::Permanent, null];
    }

    #[DataProvider('errors')]
    public function testApiErrorsAreClassified(int $status, string $fixture, ErrorKind $kind, ?int $retryAfter): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/messages', $status, MaxFixtures::raw($fixture));

        try {
            $this->client->sendMessage(-5, ['text' => 'x']);
            self::fail('expected a PlatformError');
        } catch (PlatformError $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($retryAfter, $e->retryAfter);
            self::assertSame($status, $e->platformCode);
            self::assertNotSame('', $e->forUser());
            self::assertStringNotContainsString('internals', $e->forUser());
            self::assertStringNotContainsString(MaxFixtures::TOKEN, $e->getMessage());
        }
    }

    public function testTheNotReadyAnswerCanBeRecognised(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/messages', 400, MaxFixtures::raw('error_400_not_ready'));
        $this->http->expect('POST', MaxFixtures::API . '/messages', 400, MaxFixtures::raw('error_400_bad'));

        foreach ([true, false] as $expected) {
            try {
                $this->client->sendMessage(-5, ['text' => 'x']);
            } catch (PlatformError $e) {
                self::assertSame($expected, MaxClient::isNotReady($e));
            }
        }
    }

    public function testAnUnreadableAnswerIsClassifiedByItsStatus(): void
    {
        $this->http->expect('GET', MaxFixtures::API . '/me', 502, '<html>bad gateway</html>');
        $this->http->expect('GET', MaxFixtures::API . '/me', 418, 'teapot');

        foreach ([ErrorKind::Temporary, ErrorKind::Permanent] as $kind) {
            try {
                $this->client->getMe();
                self::fail();
            } catch (PlatformError $e) {
                self::assertSame($kind, $e->kind);
            }
        }
    }

    public function testALostConnectionIsUnknownOnlyForPublishingCalls(): void
    {
        $failing = new class () implements \App\Kernel\HttpClient\HttpClientInterface {
            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new RequestException('timeout with ' . ($options['headers']['Authorization'] ?? ''), new Request($method, $url));
            }
        };
        $client = new MaxClient($failing, MaxFixtures::TOKEN);

        try {
            $client->sendMessage(-5, ['text' => 'x']);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind);
            self::assertStringNotContainsString(MaxFixtures::TOKEN, $e->getMessage(), 'the token never reaches an error message');
            self::assertNull($e->getPrevious());
        }
        try {
            $client->getMe();
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testAFailureToConnectProvesNothingWasSent(): void
    {
        $failing = new class () implements \App\Kernel\HttpClient\HttpClientInterface {
            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new ConnectException('refused', new Request($method, $url));
            }
        };

        try {
            (new MaxClient($failing, MaxFixtures::TOKEN))->sendMessage(-5, ['text' => 'x']);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testSubscriptionsAndUpdates(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/subscriptions', 200, MaxFixtures::raw('success_true'));
        $this->http->expect('GET', MaxFixtures::API . '/subscriptions', 200, MaxFixtures::raw('subscriptions'));
        $this->http->expect('DELETE', MaxFixtures::API . '/subscriptions', 200, MaxFixtures::raw('success_true'));
        $this->http->expect('GET', MaxFixtures::API . '/updates', 200, MaxFixtures::raw('updates_connect'));

        $this->client->subscribe('https://x.example/webhooks/max/s', ['message_created'], 'hdr');
        $subscriptions = $this->client->getSubscriptions();
        $this->client->unsubscribe($subscriptions[0]['url']);
        $batch = $this->client->getUpdates(5, 0, ['message_created']);

        self::assertSame(['url' => 'https://x.example/webhooks/max/s', 'update_types' => ['message_created'], 'secret' => 'hdr'], $this->http->requests[0]['options']['json']);
        self::assertSame(777, $batch['marker']);
        self::assertSame(5, $this->http->requests[3]['options']['query']['marker']);
        self::assertSame('message_created', $this->http->requests[3]['options']['query']['types']);
    }

    public function testTheWebhookNeedsBothSecrets(): void
    {
        $header = MaxWebhook::headerToken('s3cret-s3cret-s3cret');

        self::assertTrue(MaxWebhook::isAuthentic('s3cret-s3cret-s3cret', 's3cret-s3cret-s3cret', $header));
        self::assertFalse(MaxWebhook::isAuthentic('s3cret-s3cret-s3cret', 's3cret-s3cret-s3cret', null));
        self::assertFalse(MaxWebhook::isAuthentic('s3cret-s3cret-s3cret', 'other', $header));
        self::assertFalse(MaxWebhook::isAuthentic('s3cret-s3cret-s3cret', 's3cret-s3cret-s3cret', 'forged'));
        self::assertFalse(MaxWebhook::isAuthentic('', '', $header));
        self::assertSame('https://x.example/webhooks/max/abc', MaxWebhook::url('https://x.example/', 'abc'));
        self::assertNotSame('s3cret-s3cret-s3cret', $header, 'the header is derived, not the secret itself');
    }

    public function testTheRateGateLetsTheFirstSendsThroughAndPassesWithoutRedis(): void
    {
        (new MaxRateGate())->wait('t', 1);
        $redis = new \Redis();
        $host = getenv('REDIS_HOST');
        $redis->connect(is_string($host) && $host !== '' ? $host : 'redis');
        $redis->select(15);
        $gate = new MaxRateGate($redis, 2);
        $chat = random_int(1, 1_000_000_000);

        $start = microtime(true);
        $gate->wait('t', $chat);
        $gate->wait('t', $chat);
        $fast = microtime(true) - $start;

        self::assertLessThan(0.5, $fast);
    }
}
