<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\Max\MaxAdapter;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxInspector;
use App\Integrations\Social\Max\MaxRateGate;
use App\Tests\Support\MaxFixtures;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxAdapter::class)]
#[CoversClass(MaxInspector::class)]
final class MaxAdapterTest extends TestCase
{
    private const CHAT = '-72000000000001';
    private const UPLOAD_IMAGE = 'https://upload.max.example/upload?type=image&id=1';
    private const UPLOAD_VIDEO = 'https://upload.max.example/upload?type=video&id=2';
    private const UPLOAD_FILE = 'https://upload.max.example/upload?type=file&id=3';

    private MockHttpClient $http;
    private MaxAdapter $adapter;
    private Credential $credential;

    /** @var list<int> pauses the adapter asked for while waiting for MAX to process files */
    private array $pauses = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->adapter = new MaxAdapter(new MaxClientFactory($this->http), new MaxInspector(), new MaxRateGate(), function (int $seconds): void {
            $this->pauses[] = $seconds;
        });
        $this->credential = new Credential(Platform::Max, MaxFixtures::TOKEN);
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function expect(string $method, string $path, string $fixture, int $status = 200): void
    {
        $this->http->expect($method, MaxFixtures::API . $path, $status, MaxFixtures::raw($fixture));
    }

    private function upload(string $url, string $fixture): void
    {
        $this->http->expect('POST', $url, 200, MaxFixtures::raw($fixture));
    }

    private function media(MediaKind $kind, string $name, int $bytes = 10): PublishMedia
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mxa');
        file_put_contents($path, str_repeat('x', $bytes));
        $this->files[] = $path;

        return new PublishMedia($kind, $path, $name, $kind === MediaKind::Image ? 'image/jpeg' : 'video/mp4');
    }

    /**
     * @return array<string, mixed> the JSON body of request number `$n`
     */
    private function body(int $n): array
    {
        return $this->http->requests[$n]['options']['json'];
    }

    public function testPlainTextGoesOutAsAMessageAndReturnsItsIdAndLink(): void
    {
        $this->expect('POST', '/messages', 'message_sent');

        $result = $this->adapter->publish(new PublishRequest('Привет'), self::CHAT, $this->credential, 'key-1');

        self::assertSame('mid.0000000000000001', $result->externalId);
        self::assertSame(['mid.0000000000000001'], $result->allIds);
        self::assertSame('https://max.ru/mychannel/AZ1234', $result->url);
        self::assertSame(['text' => 'Привет'], $this->body(0));
        self::assertSame(['chat_id' => -72000000000001], $this->http->requests[0]['options']['query']);
    }

    public function testHtmlSilentPreviewAndButtons(): void
    {
        $this->expect('POST', '/messages', 'message_sent');

        $this->adapter->publish(new PublishRequest('<b>Жирный</b>', [], [['text' => 'Сайт', 'url' => 'https://example.com'], ['text' => 'Ещё', 'url' => 'https://example.org']], null, true, 'html', true), self::CHAT, $this->credential, 'k');

        $body = $this->body(0);
        self::assertSame('html', $body['format']);
        self::assertFalse($body['notify']);
        self::assertSame('inline_keyboard', $body['attachments'][0]['type']);
        self::assertSame([[['type' => 'link', 'text' => 'Сайт', 'url' => 'https://example.com']], [['type' => 'link', 'text' => 'Ещё', 'url' => 'https://example.org']]], $body['attachments'][0]['payload']['buttons']);
        self::assertSame('true', $this->http->requests[0]['options']['query']['disable_link_preview']);
    }

    public function testAPhotoVideoAndFileAreUploadedFirstAndAttachedByToken(): void
    {
        $this->expect('POST', '/uploads', 'upload_slot_image');
        $this->upload(self::UPLOAD_IMAGE, 'upload_image_done');
        $this->expect('POST', '/uploads', 'upload_slot_video');
        $this->upload(self::UPLOAD_VIDEO, 'upload_video_done');
        $this->expect('POST', '/uploads', 'upload_slot_file');
        $this->upload(self::UPLOAD_FILE, 'upload_video_done');
        $this->expect('POST', '/messages', 'message_sent');
        $media = [$this->media(MediaKind::Image, 'a.jpg'), $this->media(MediaKind::Video, 'b.mp4'), $this->media(MediaKind::Document, 'c.pdf')];

        $this->adapter->publish(new PublishRequest('Подпись', $media), self::CHAT, $this->credential, 'k');

        self::assertSame(['type' => 'image'], $this->http->requests[0]['options']['query']);
        self::assertSame(['type' => 'video'], $this->http->requests[2]['options']['query']);
        self::assertSame(['type' => 'file'], $this->http->requests[4]['options']['query']);
        $body = $this->body(6);
        self::assertSame('Подпись', $body['text']);
        self::assertSame([
            ['type' => 'image', 'payload' => ['token' => 'photo-token-xyz']],
            ['type' => 'video', 'payload' => ['token' => 'video-token-abc']],
            ['type' => 'file', 'payload' => ['token' => 'file-token-def']],
        ], $body['attachments']);
    }

    public function testAFileThatIsStillBeingProcessedIsWaitedFor(): void
    {
        $this->expect('POST', '/uploads', 'upload_slot_video');
        $this->upload(self::UPLOAD_VIDEO, 'upload_video_done');
        $this->expect('POST', '/messages', 'error_400_not_ready', 400);
        $this->expect('POST', '/messages', 'error_400_not_ready', 400);
        $this->expect('POST', '/messages', 'message_sent');

        $result = $this->adapter->publish(new PublishRequest('', [$this->media(MediaKind::Video, 'b.mp4')]), self::CHAT, $this->credential, 'k');

        self::assertSame('mid.0000000000000001', $result->externalId);
        self::assertSame([1, 2], $this->pauses, 'it waited with growing pauses and then sent');
        self::assertCount(5, $this->http->requests);
    }

    public function testAFileThatNeverGetsReadyEndsAsATemporaryError(): void
    {
        $this->expect('POST', '/uploads', 'upload_slot_video');
        $this->upload(self::UPLOAD_VIDEO, 'upload_video_done');
        for ($i = 0; $i < 7; ++$i) {
            $this->expect('POST', '/messages', 'error_400_not_ready', 400);
        }

        try {
            $this->adapter->publish(new PublishRequest('', [$this->media(MediaKind::Video, 'b.mp4')]), self::CHAT, $this->credential, 'k');
            self::fail('expected a PlatformError');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind, 'nothing was posted, so the pipeline may retry later');
            self::assertSame([1, 2, 4, 8, 15, 30], $this->pauses);
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function testATextOnlyPostDoesNotWaitForFiles(): void
    {
        $this->expect('POST', '/messages', 'error_400_not_ready', 400);

        try {
            $this->adapter->publish(new PublishRequest('Привет'), self::CHAT, $this->credential, 'k');
            self::fail();
        } catch (PlatformError) {
            self::assertSame([], $this->pauses);
        }
    }

    public function testAQuietPostIsSentLoudWhenTheChannelRefusesIt(): void
    {
        $this->http->expect('POST', MaxFixtures::API . '/messages', 400, MaxFixtures::raw('error_400_notify'));
        $this->expect('POST', '/messages', 'message_sent');

        $result = $this->adapter->publish(new PublishRequest('Тихо', [], [], null, true), self::CHAT, $this->credential, 'k');

        self::assertSame('mid.0000000000000001', $result->externalId);
        self::assertFalse($this->body(0)['notify']);
        self::assertArrayNotHasKey('notify', $this->body(1));
    }

    public function testOtherRefusalsOfAQuietPostAreNotRetried(): void
    {
        $this->expect('POST', '/messages', 'error_401', 401);

        $this->expectException(PlatformError::class);
        try {
            $this->adapter->publish(new PublishRequest('Тихо', [], [], null, true), self::CHAT, $this->credential, 'k');
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testAnAnswerWithoutAMessageIdIsAnUnknownOutcome(): void
    {
        $this->expect('POST', '/messages', 'message_sent_nobody');

        try {
            $this->adapter->publish(new PublishRequest('x'), self::CHAT, $this->credential, 'k');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind);
        }
    }

    public function testAGroupPostHasNoPublicLink(): void
    {
        $this->expect('POST', '/messages', 'message_sent_group');

        $result = $this->adapter->publish(new PublishRequest('x'), '-72000000000002', $this->credential, 'k');

        self::assertNull($result->url);
    }

    public function testAnImageWithoutATokenFailsTemporarily(): void
    {
        $this->expect('POST', '/uploads', 'upload_slot_image');
        $this->upload(self::UPLOAD_IMAGE, 'upload_image_nothing');

        try {
            $this->adapter->publish(new PublishRequest('', [$this->media(MediaKind::Image, 'a.jpg')]), self::CHAT, $this->credential, 'k');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
            self::assertStringContainsString('a.jpg', $e->forUser());
        }
    }

    public function testANonNumericChannelIdIsRefused(): void
    {
        try {
            $this->adapter->publish(new PublishRequest('x'), 'abc', $this->credential, 'k');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
        }
    }

    public function testDeleteEditAndPin(): void
    {
        $published = new PublishResult('mid.7', null, ['mid.7', 'mid.8']);
        foreach (['DELETE /messages', 'DELETE /messages', 'PUT /messages', 'PUT /chats/-72000000000001/pin', 'DELETE /chats/-72000000000001/pin'] as $call) {
            [$method, $path] = explode(' ', $call);
            $this->expect($method, $path, 'success_true');
        }

        $this->adapter->delete($published, self::CHAT, $this->credential);
        $this->adapter->edit($published, self::CHAT, $this->credential, new PublishRequest('<i>Новый</i>', [], [['text' => 'x', 'url' => 'https://e.com']], null, false, 'html'), true);
        $this->adapter->pin($published, self::CHAT, $this->credential, true);
        $this->adapter->pin($published, self::CHAT, $this->credential, false);

        self::assertSame(['message_id' => 'mid.7'], $this->http->requests[0]['options']['query']);
        self::assertSame(['message_id' => 'mid.8'], $this->http->requests[1]['options']['query']);
        self::assertSame(['text' => '<i>Новый</i>', 'format' => 'html'], $this->body(2), 'attachments are left out, so MAX keeps the files');
        self::assertSame(['message_id' => 'mid.7'], $this->http->requests[2]['options']['query']);
        self::assertSame(['message_id' => 'mid.7', 'notify' => false], $this->body(3));
    }

    public function testDeleteOfASingleMessageWithoutAllIds(): void
    {
        $this->expect('DELETE', '/messages', 'success_true');

        $this->adapter->delete(new PublishResult('mid.9', null), self::CHAT, $this->credential);

        self::assertSame(['message_id' => 'mid.9'], $this->http->requests[0]['options']['query']);
    }

    public function testValidationExplainsEveryProblem(): void
    {
        $good = $this->adapter->validate(new PublishRequest('Привет', [$this->media(MediaKind::Image, 'a.jpg')], [['text' => 'Сайт', 'url' => 'https://example.com']]));
        self::assertSame([], $good);

        self::assertStringContainsString('опрос', $this->adapter->validate(new PublishRequest('', [], [], ['question' => 'q', 'options' => ['a', 'b'], 'anonymous' => true, 'multiple' => false]))[0]);
        self::assertStringContainsString('пустой пост', $this->adapter->validate(new PublishRequest(''))[0]);
        self::assertStringContainsString('4010 из 4000', $this->adapter->validate(new PublishRequest(str_repeat('я', 4010)))[0]);
        self::assertSame([], $this->adapter->validate(new PublishRequest('<b>' . str_repeat('я', 3990) . '</b>', [], [], null, false, 'html')));
        $many = array_map(fn (int $i): PublishMedia => $this->media(MediaKind::Image, $i . '.jpg'), range(1, 11));
        self::assertStringContainsString('не больше 10', $this->adapter->validate(new PublishRequest('x', $many))[0]);
        self::assertStringContainsString('http', $this->adapter->validate(new PublishRequest('x', [], [['text' => 'a', 'url' => 'javascript:alert(1)']]))[0]);
        self::assertStringContainsString('128', $this->adapter->validate(new PublishRequest('x', [], [['text' => str_repeat('a', 129), 'url' => 'https://e.com']]))[0]);
        $buttons = array_fill(0, 31, ['text' => 'a', 'url' => 'https://e.com']);
        self::assertStringContainsString('30 кнопок', $this->adapter->validate(new PublishRequest('x', [], $buttons))[0]);
    }

    public function testValidationChecksFileSizes(): void
    {
        $sparse = (string) tempnam(sys_get_temp_dir(), 'mxbig');
        $this->files[] = $sparse;
        $handle = fopen($sparse, 'wb');
        self::assertNotFalse($handle);
        ftruncate($handle, 251 * 1024 * 1024);
        fclose($handle);
        $big = new PublishMedia(MediaKind::Video, $sparse, 'big.mp4', 'video/mp4');

        self::assertStringContainsString('больше 250 МБ', $this->adapter->validate(new PublishRequest('x', [$big]))[0]);
    }

    public function testCapabilitiesDescribeMax(): void
    {
        $caps = $this->adapter->capabilities();

        self::assertSame(4000, $caps->maxText);
        self::assertSame('html', $caps->textFormat);
        self::assertFalse($caps->polls);
        self::assertTrue($caps->buttons);
        self::assertTrue($caps->pin);
        self::assertTrue($caps->disablePreview);
        self::assertSame(Platform::Max, $this->adapter->platform());
    }

    public function testHealthCheckReportsRights(): void
    {
        $this->expect('GET', '/chats/-72000000000001', 'chat_channel');
        $this->expect('GET', '/chats/-72000000000001/members/me', 'member_admin_limited');

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertTrue($status->ok);
        self::assertSame('Мой канал', $status->title);
        self::assertSame(['post' => true, 'edit' => true, 'delete' => false, 'pin' => false], $status->rights);
    }

    public function testHealthCheckNoticesLostPostingRight(): void
    {
        $this->expect('GET', '/chats/-72000000000001', 'chat_channel');
        $this->expect('GET', '/chats/-72000000000001/members/me', 'member_admin_no_write');

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertFalse($status->ok);
        self::assertFalse($status->revoked);
        self::assertStringContainsString('права публиковать', $status->message);
    }

    public function testHealthCheckNoticesARemovedBotAndAGoneChat(): void
    {
        $this->expect('GET', '/chats/-72000000000001', 'chat_removed');
        $removed = $this->adapter->healthCheck(self::CHAT, $this->credential);
        self::assertFalse($removed->ok);
        self::assertTrue($removed->revoked);

        $this->expect('GET', '/chats/-72000000000001', 'error_404_chat', 404);
        $gone = $this->adapter->healthCheck(self::CHAT, $this->credential);
        self::assertFalse($gone->ok);
        self::assertTrue($gone->revoked);
    }

    public function testHealthCheckKeepsTheStatusWhenMaxIsDown(): void
    {
        $this->expect('GET', '/chats/-72000000000001', 'error_500', 500);
        $down = $this->adapter->healthCheck(self::CHAT, $this->credential);
        self::assertTrue($down->transient);

        $this->expect('GET', '/chats/-72000000000001', 'error_401', 401);
        $badToken = $this->adapter->healthCheck(self::CHAT, $this->credential);
        self::assertFalse($badToken->ok);
        self::assertFalse($badToken->transient);
        self::assertFalse($badToken->revoked, 'a bad token needs a new token, not a reconnect of the chat');
    }

    public function testConnectByLinkChecksTheTokenFirst(): void
    {
        $this->expect('GET', '/me', 'me');
        $this->expect('GET', '/chats/mychannel', 'chat_channel');
        $this->expect('GET', '/chats/-72000000000001/members/me', 'member_admin');

        $info = $this->adapter->connect($this->credential, 'https://max.ru/mychannel');

        self::assertSame('-72000000000001', $info->externalId);
        self::assertSame('Мой канал', $info->title);
        self::assertSame('mychannel', $info->username);
        self::assertSame('channel', $info->kind);
        self::assertTrue($info->rights['post']);
    }

    public function testConnectTriesTheLinkWithAnAtSignWhenTheBareNameIsNotFound(): void
    {
        $this->expect('GET', '/me', 'me');
        $this->expect('GET', '/chats/mychannel', 'error_404_chat', 404);
        $this->expect('GET', '/chats/%40mychannel', 'chat_channel');
        $this->expect('GET', '/chats/-72000000000001/members/me', 'member_owner');

        $info = $this->adapter->connect($this->credential, '@mychannel');

        self::assertSame('-72000000000001', $info->externalId);
        $this->http->assertAllConsumed();
    }

    public function testConnectByNumberAndAGroup(): void
    {
        $this->expect('GET', '/me', 'me');
        $this->expect('GET', '/chats/-72000000000002', 'chat_group');
        $this->expect('GET', '/chats/-72000000000002/members/me', 'member_plain');

        $info = $this->adapter->connect($this->credential, ' -72000000000002 ');

        self::assertSame('group', $info->kind);
        self::assertNull($info->username);
        self::assertTrue($info->rights['post'], 'any member of a group chat can write');
        self::assertFalse($info->rights['delete']);
    }

    public function testConnectWithABadTokenSaysSo(): void
    {
        $this->expect('GET', '/me', 'error_401', 401);

        try {
            $this->adapter->connect($this->credential, 'mychannel');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringContainsString('Токен', $e->forUser());
        }
    }

    public function testConnectRefusesWhatIsNotAChannelOrWhereTheBotCannotPost(): void
    {
        $this->expect('GET', '/me', 'me');
        $this->expect('GET', '/chats/900001', 'chat_dialog');
        try {
            $this->adapter->connect($this->credential, '900001');
            self::fail();
        } catch (PlatformError $e) {
            self::assertStringContainsString('Это не канал', $e->forUser());
        }

        $this->expect('GET', '/me', 'me');
        $this->expect('GET', '/chats/-72000000000001', 'chat_channel');
        $this->expect('GET', '/chats/-72000000000001/members/me', 'member_plain');
        try {
            $this->adapter->connect($this->credential, '-72000000000001');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringContainsString('не может публиковать', $e->forUser());
        }
    }

    public function testConnectRefusesAnInviteLinkAndGibberish(): void
    {
        foreach (['https://max.ru/join/abcdef123', 'два слова', '', 'x'] as $input) {
            try {
                $this->adapter->connect($this->credential, $input);
                self::fail('accepted ' . $input);
            } catch (PlatformError $e) {
                self::assertStringContainsString('Не поняли', $e->forUser());
            }
        }
        self::assertSame([], $this->http->requests, 'nothing is asked from MAX for a reference that cannot be one');
    }

    /**
     * @return iterable<string, array{string, int|string|null}>
     */
    public static function references(): iterable
    {
        yield 'id' => ['-72000000000001', -72000000000001];
        yield 'positive id' => ['123456', 123456];
        yield 'link' => ['https://max.ru/mychannel', 'mychannel'];
        yield 'link without scheme' => ['max.ru/my.channel_1/', 'my.channel_1'];
        yield 'at' => ['@mychannel', 'mychannel'];
        yield 'bare name' => ['mychannel', 'mychannel'];
        yield 'invite' => ['https://max.ru/join/abc', null];
        yield 'other site' => ['https://example.com/mychannel', null];
        yield 'too short' => ['ab', null];
    }

    #[DataProvider('references')]
    public function testReferencesAreParsed(string $input, int|string|null $expected): void
    {
        self::assertSame($expected, MaxInspector::parseReference($input));
    }

    public function testRightsFollowTheRoleAndThePermissionList(): void
    {
        $full = ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true];
        $none = ['post' => false, 'edit' => false, 'delete' => false, 'pin' => false];

        self::assertSame($full, MaxInspector::rights(['is_owner' => true], 'channel'));
        self::assertSame($full, MaxInspector::rights(['is_admin' => true], 'channel'), 'an administrator without a list is trusted');
        self::assertSame($none, MaxInspector::rights(['is_admin' => false], 'channel'));
        self::assertTrue(MaxInspector::rights(['is_admin' => false], 'chat')['post']);
        self::assertSame(['post' => true, 'edit' => false, 'delete' => false, 'pin' => true], MaxInspector::rights(['is_admin' => true, 'permissions' => ['write', 'pin_message']], 'channel'));
        self::assertNull(MaxInspector::usernameFromLink('https://max.ru/join/xyz'));
        self::assertNull(MaxInspector::usernameFromLink(null));
    }
}
