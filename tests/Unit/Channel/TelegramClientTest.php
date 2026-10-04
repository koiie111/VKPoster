<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClient;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(TelegramClient::class)]
final class TelegramClientTest extends TestCase
{
    private const TOKEN = TestEnv::SHARED_BOT_TOKEN;
    private const BASE = 'https://api.telegram.org/bot' . self::TOKEN . '/';

    public function testGetMeReturnsTheResult(): void
    {
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'getMe', 200, TelegramFixtures::raw('get_me'));

        $me = (new TelegramClient($http, self::TOKEN))->getMe();

        self::assertSame(987654321, $me['id']);
        self::assertSame('ezposter_bot', $me['username']);
    }

    public function testSendMessageSendsJsonWithChatAndText(): void
    {
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'sendMessage', 200, TelegramFixtures::raw('send_message'));

        $message = (new TelegramClient($http, self::TOKEN))->sendMessage(-1001234567890, 'Привет', ['disable_notification' => true]);

        self::assertSame(77, $message['message_id']);
        self::assertSame(['chat_id' => -1001234567890, 'text' => 'Привет', 'disable_notification' => true], $http->requests[0]['options']['json']);
    }

    public function testFilesAreSentAsMultipartWithTheParametersAsFields(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tg');
        file_put_contents($path, 'JPEGDATA');
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'sendPhoto', 200, TelegramFixtures::raw('send_message'));

        (new TelegramClient($http, self::TOKEN))->sendPhoto(-1001, $path, 'cat.jpg', ['caption' => 'Кот', 'disable_notification' => true]);
        unlink($path);

        $parts = [];
        foreach ($http->requests[0]['options']['multipart'] as $part) {
            $parts[$part['name']] = $part;
        }
        self::assertSame('-1001', $parts['chat_id']['contents']);
        self::assertSame('Кот', $parts['caption']['contents']);
        self::assertSame('true', $parts['disable_notification']['contents']);
        self::assertSame('cat.jpg', $parts['photo']['filename']);
        self::assertArrayNotHasKey('json', $http->requests[0]['options']);
    }

    public function testMediaGroupReferencesItsFilesByAttachName(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tg');
        file_put_contents($path, 'X');
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'sendMediaGroup', 200, TelegramFixtures::raw('send_media_group'));

        $messages = (new TelegramClient($http, self::TOKEN))->sendMediaGroup(-1001, [
            ['type' => 'photo', 'path' => $path, 'filename' => 'a.jpg', 'caption' => 'Подпись'],
            ['type' => 'photo', 'path' => $path, 'filename' => 'b.jpg'],
        ]);
        unlink($path);

        self::assertCount(3, $messages);
        $fields = array_column($http->requests[0]['options']['multipart'], null, 'name');
        $media = json_decode($fields['media']['contents'], true);
        self::assertSame('attach://file0', $media[0]['media']);
        self::assertSame('Подпись', $media[0]['caption']);
        self::assertArrayNotHasKey('caption', $media[1]);
        self::assertSame('b.jpg', $fields['file1']['filename']);
    }

    /**
     * @return array<string, array{string, int, ErrorKind, ?int}>
     */
    public static function errorCases(): array
    {
        return [
            'rate limit' => ['error_429', 429, ErrorKind::RateLimited, 7],
            'bad token' => ['error_401', 401, ErrorKind::Auth, null],
            'kicked from channel' => ['error_403_kicked', 403, ErrorKind::Auth, null],
            'no such chat' => ['error_400_chat_not_found', 400, ErrorKind::Permanent, null],
            'text too long' => ['error_400_too_long', 400, ErrorKind::Permanent, null],
            'no rights' => ['error_400_not_enough_rights', 400, ErrorKind::Auth, null],
            'gateway page instead of json' => ['error_500', 502, ErrorKind::Temporary, null],
        ];
    }

    #[DataProvider('errorCases')]
    public function testApiErrorsAreClassified(string $fixture, int $status, ErrorKind $kind, ?int $retryAfter): void
    {
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'sendMessage', $status, TelegramFixtures::raw($fixture));

        try {
            (new TelegramClient($http, self::TOKEN))->sendMessage(-1, 'x');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($retryAfter, $e->retryAfter);
            self::assertNotSame('', $e->forUser());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->forUser());
        }
    }

    public function testRateLimitWithoutRetryAfterStillHasADelay(): void
    {
        $http = (new MockHttpClient())->expect('POST', self::BASE . 'getMe', 429, '{"ok":false,"error_code":429,"description":"Too Many Requests"}');

        try {
            (new TelegramClient($http, self::TOKEN))->getMe();
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
            self::assertSame(5, $e->retryAfter);
        }
    }

    public function testFailureToConnectIsTemporary(): void
    {
        $error = $this->transportFailure(true);

        try {
            $error->sendMessage(-1, 'x');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testTimeoutWhileWaitingForTheAnswerIsAnUnknownOutcome(): void
    {
        try {
            $this->transportFailure(false)->sendMessage(-1, 'x');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind, 'a retry could post twice');
        }
    }

    public function testTimeoutOfAReadOnlyCallIsJustTemporary(): void
    {
        try {
            $this->transportFailure(false)->getChat(-1);
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testTheTokenNeverLeaksThroughTransportErrors(): void
    {
        try {
            $this->transportFailure(false)->sendMessage(-1, 'x');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('SHARED-bot-token', $e->getMessage());
            self::assertNull($e->getPrevious(), 'the Guzzle exception carries the URL with the token');
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
        }
    }

    public function testDownloadFileChecksSizeAndStatus(): void
    {
        $http = (new MockHttpClient())
            ->expect('GET', 'https://api.telegram.org/file/bot' . self::TOKEN . '/photos/a.jpg', 200, 'abc')
            ->expect('GET', 'https://api.telegram.org/file/bot' . self::TOKEN . '/photos/b.jpg', 200, str_repeat('x', 50))
            ->expect('GET', 'https://api.telegram.org/file/bot' . self::TOKEN . '/photos/c.jpg', 404, '');
        $client = new TelegramClient($http, self::TOKEN);

        self::assertSame('abc', $client->downloadFile('photos/a.jpg', 10));
        $this->expectException(PlatformError::class);
        try {
            $client->downloadFile('photos/b.jpg', 10);
        } catch (PlatformError) {
            $client->downloadFile('photos/c.jpg', 10);
        }
    }

    private function transportFailure(bool $beforeSending): TelegramClient
    {
        $http = new class ($beforeSending) implements HttpClientInterface {
            public function __construct(private readonly bool $beforeSending)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $request = new Request($method, $url);
                $message = 'transport error for ' . $url;

                throw $this->beforeSending ? new ConnectException($message, $request) : new NetworkTimeoutException($message, $request);
            }
        };

        return new TelegramClient($http, self::TOKEN);
    }

    public function testOnlyRealLookingFilePathsAreAccepted(): void
    {
        $http = new MockHttpClient();
        $client = new TelegramClient($http, self::TOKEN);
        $http->expect('POST', self::BASE . 'getFile', 200, TelegramFixtures::raw('get_file'));
        self::assertSame('photos/file_1.jpg', $client->getFilePath('AVATAR_SMALL'));

        foreach (['../../etc/passwd', 'photos/../x', 'http://evil.example/x', "photos/a\nb", ''] as $bad) {
            $http->expect('POST', self::BASE . 'getFile', 200, json_encode(['ok' => true, 'result' => ['file_path' => $bad]], JSON_THROW_ON_ERROR));
            $rejected = false;
            try {
                $client->getFilePath('x');
            } catch (PlatformError) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'path accepted: ' . $bad);
        }
    }

    public function testTheRemainingMethodsCallTheirBotApiEndpoints(): void
    {
        $http = new MockHttpClient();
        $client = new TelegramClient($http, self::TOKEN);
        $http->expect('POST', self::BASE . 'getChatAdministrators', 200, '{"ok":true,"result":[{"status":"creator","user":{"id":1}},{"status":"administrator","user":{"id":2}}]}');
        $http->expect('POST', self::BASE . 'editMessageText', 200, TelegramFixtures::raw('send_message'));
        $http->expect('POST', self::BASE . 'sendPoll', 200, TelegramFixtures::raw('send_poll'));
        $http->expect('POST', self::BASE . 'getUpdates', 200, '{"ok":true,"result":[{"update_id":5},"junk"]}');
        $http->expect('POST', self::BASE . 'setWebhook', 200, TelegramFixtures::raw('ok_true'));
        $http->expect('POST', self::BASE . 'deleteWebhook', 200, TelegramFixtures::raw('ok_true'));
        $http->expect('POST', self::BASE . 'getWebhookInfo', 200, '{"ok":true,"result":{"url":"","pending_update_count":0}}');
        $http->expect('POST', self::BASE . 'unpinChatMessage', 200, TelegramFixtures::raw('ok_true'));
        $http->expect('POST', self::BASE . 'pinChatMessage', 200, TelegramFixtures::raw('ok_true'));
        $http->expect('POST', self::BASE . 'deleteMessage', 200, TelegramFixtures::raw('ok_true'));

        self::assertCount(2, $client->getChatAdministrators(-1001));
        self::assertSame(77, $client->editMessageText(-1001, 77, 'Новый текст', ['parse_mode' => 'HTML'])['message_id']);
        self::assertSame(90, $client->sendPoll(-1001, 'Да?', ['Да', 'Нет'], ['is_anonymous' => false])['message_id']);
        self::assertSame([['update_id' => 5]], $client->getUpdates(3, 25, ['message']), 'non-object entries are dropped');
        $client->setWebhook('https://example.com/hook', 'secret-token', ['message']);
        $client->deleteWebhook();
        self::assertSame(0, $client->getWebhookInfo()['pending_update_count']);
        $client->unpinChatMessage(-1001, 5);
        $client->pinChatMessage(-1001, 5, false);
        $client->deleteMessage(-1001, 5);

        self::assertSame(35, $http->requests[3]['options']['timeout'], 'long polling waits longer than the poll itself (25 s + 10 s)');
        self::assertSame(false, $http->requests[8]['options']['json']['disable_notification']);
        self::assertSame(['url' => 'https://example.com/hook', 'secret_token' => 'secret-token', 'allowed_updates' => ['message'], 'drop_pending_updates' => false], $http->requests[4]['options']['json']);
        $http->assertAllConsumed();
    }

    public function testAnUnreadableFileIsAPermanentError(): void
    {
        $client = new TelegramClient(new MockHttpClient(), self::TOKEN);

        $this->expectException(PlatformError::class);
        $client->sendDocument(-1, '/nonexistent/file.pdf', 'file.pdf');
    }

    public function testAFailedDownloadIsTemporary(): void
    {
        $http = new class () implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new ConnectException('down', new Request($method, $url));
            }
        };

        try {
            (new TelegramClient($http, self::TOKEN))->downloadFile('photos/a.jpg', 100);
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }
}
