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
use App\Integrations\Social\Telegram\TelegramAdapter;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Integrations\Social\Telegram\TelegramInspector;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TelegramFixtures;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramAdapter::class)]
#[CoversClass(TelegramInspector::class)]
final class TelegramAdapterTest extends TestCase
{
    private const TOKEN = TestEnv::SHARED_BOT_TOKEN;
    private const BASE = 'https://api.telegram.org/bot' . self::TOKEN . '/';
    private const CHAT = '-1001234567890';

    private MockHttpClient $http;
    private TelegramAdapter $adapter;
    private Credential $credential;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->adapter = new TelegramAdapter(new TelegramClientFactory($this->http), new TelegramInspector());
        $this->credential = new Credential(Platform::Telegram, self::TOKEN);
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function expect(string $method, string $fixture, int $status = 200): void
    {
        $this->http->expect('POST', self::BASE . $method, $status, TelegramFixtures::raw($fixture));
    }

    private function media(MediaKind $kind, string $name, int $bytes = 10): PublishMedia
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tga');
        file_put_contents($path, str_repeat('x', $bytes));
        $this->files[] = $path;

        return new PublishMedia($kind, $path, $name, $kind === MediaKind::Image ? 'image/jpeg' : 'video/mp4');
    }

    /**
     * @return array<string, mixed> the JSON or multipart fields of request number `$n`
     */
    private function sent(int $n): array
    {
        $options = $this->http->requests[$n]['options'];
        if (isset($options['json'])) {
            return $options['json'];
        }
        $fields = [];
        foreach ($options['multipart'] as $part) {
            $fields[$part['name']] = $part['contents'];
        }

        return $fields;
    }

    public function testPlainTextGoesOutAsAMessageAndReturnsItsId(): void
    {
        $this->expect('sendMessage', 'send_message');

        $result = $this->adapter->publish(new PublishRequest('Привет'), self::CHAT, $this->credential, 'key-1');

        self::assertSame('77', $result->externalId);
        self::assertSame(['77'], $result->allIds);
        self::assertSame('https://t.me/c/1234567890/77', $result->url);
        self::assertSame('Привет', $this->sent(0)['text']);
        self::assertSame(-1001234567890, $this->sent(0)['chat_id']);
        self::assertArrayNotHasKey('parse_mode', $this->sent(0));
    }

    public function testHtmlSilentAndButtons(): void
    {
        $this->expect('sendMessage', 'send_message');

        $this->adapter->publish(new PublishRequest('<b>Жирный</b>', [], [['text' => 'Сайт', 'url' => 'https://example.com']], null, true, 'html'), self::CHAT, $this->credential, 'k');

        $sent = $this->sent(0);
        self::assertSame('HTML', $sent['parse_mode']);
        self::assertTrue($sent['disable_notification']);
        self::assertSame([[['text' => 'Сайт', 'url' => 'https://example.com']]], $sent['reply_markup']['inline_keyboard']);
    }

    public function testASinglePhotoCarriesTheTextAsCaption(): void
    {
        $this->expect('sendPhoto', 'send_message');

        $this->adapter->publish(new PublishRequest('Подпись', [$this->media(MediaKind::Image, 'a.jpg')]), self::CHAT, $this->credential, 'k');

        self::assertSame('Подпись', $this->sent(0)['caption']);
    }

    public function testVideoAndDocumentUseTheirOwnMethods(): void
    {
        $this->expect('sendVideo', 'send_message');
        $this->expect('sendDocument', 'send_message');

        $this->adapter->publish(new PublishRequest('', [$this->media(MediaKind::Video, 'v.mp4')]), self::CHAT, $this->credential, 'k');
        $this->adapter->publish(new PublishRequest('', [$this->media(MediaKind::Document, 'd.pdf')]), self::CHAT, $this->credential, 'k');

        self::assertStringEndsWith('/sendVideo', $this->http->requests[0]['url']);
        self::assertStringEndsWith('/sendDocument', $this->http->requests[1]['url']);
        self::assertArrayNotHasKey('caption', $this->sent(0));
    }

    public function testAnAlbumIsOneRequestAndReturnsEveryMessageId(): void
    {
        $this->expect('sendMediaGroup', 'send_media_group');
        $items = [$this->media(MediaKind::Image, 'a.jpg'), $this->media(MediaKind::Image, 'b.jpg'), $this->media(MediaKind::Video, 'c.mp4')];

        $result = $this->adapter->publish(new PublishRequest('Альбом', $items), self::CHAT, $this->credential, 'k');

        self::assertSame('80', $result->externalId);
        self::assertSame(['80', '81', '82'], $result->allIds);
        $media = json_decode($this->sent(0)['media'], true);
        self::assertSame(['photo', 'photo', 'video'], array_column($media, 'type'));
        self::assertSame('Альбом', $media[0]['caption']);
    }

    public function testTextLongerThanACaptionIsSentAfterTheMedia(): void
    {
        $this->expect('sendPhoto', 'send_message');
        $this->expect('sendMessage', 'send_message_2');
        $long = str_repeat('Я', 1500);

        $result = $this->adapter->publish(new PublishRequest($long, [$this->media(MediaKind::Image, 'a.jpg')], [['text' => 'Сайт', 'url' => 'https://example.com']]), self::CHAT, $this->credential, 'k');

        self::assertSame(['77', '78'], $result->allIds);
        self::assertArrayNotHasKey('caption', $this->sent(0));
        self::assertArrayNotHasKey('reply_markup', $this->sent(0));
        self::assertSame($long, $this->sent(1)['text']);
        self::assertArrayHasKey('reply_markup', $this->sent(1), 'the buttons sit under the text');
    }

    public function testWhenTheTextAfterTheMediaFailsTheOutcomeIsUnknownNotFailed(): void
    {
        $this->expect('sendPhoto', 'send_message');
        $this->expect('sendMessage', 'error_400_too_long', 400);

        try {
            $this->adapter->publish(new PublishRequest(str_repeat('Я', 1500), [$this->media(MediaKind::Image, 'a.jpg')]), self::CHAT, $this->credential, 'k');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind, 'the media is already out: a retry would duplicate it');
        }
    }

    public function testPollGoesThroughSendPoll(): void
    {
        $this->expect('sendPoll', 'send_poll');

        $result = $this->adapter->publish(new PublishRequest('', poll: ['question' => 'Да?', 'options' => ['Да', 'Нет'], 'anonymous' => true, 'multiple' => false]), self::CHAT, $this->credential, 'k');

        self::assertSame('90', $result->externalId);
        self::assertSame([['text' => 'Да'], ['text' => 'Нет']], $this->sent(0)['options']);
    }

    public function testDeleteRemovesEveryMessageOfAnAlbum(): void
    {
        $this->expect('deleteMessage', 'ok_true');
        $this->expect('deleteMessage', 'ok_true');

        $this->adapter->delete(new PublishResult('80', null, ['80', '81']), self::CHAT, $this->credential);

        self::assertSame(80, $this->sent(0)['message_id']);
        self::assertSame(81, $this->sent(1)['message_id']);
    }

    public function testPinAndUnpin(): void
    {
        $this->expect('pinChatMessage', 'ok_true');
        $this->expect('unpinChatMessage', 'ok_true');

        $this->adapter->pin(new PublishResult('77', null, ['77']), self::CHAT, $this->credential, true);
        $this->adapter->pin(new PublishResult('77', null, ['77']), self::CHAT, $this->credential, false);

        self::assertTrue($this->sent(0)['disable_notification']);
        self::assertStringEndsWith('/unpinChatMessage', $this->http->requests[1]['url']);
    }

    public function testValidateReportsEveryProblemInRussian(): void
    {
        self::assertSame([], $this->adapter->validate(new PublishRequest('Нормальный текст')));
        self::assertStringContainsString('пустой пост', $this->adapter->validate(new PublishRequest(''))[0]);
        self::assertStringContainsString('4096', $this->adapter->validate(new PublishRequest(str_repeat('a', 4097)))[0]);

        $many = array_map(fn (int $i): PublishMedia => $this->media(MediaKind::Image, $i . '.jpg'), range(1, 11));
        self::assertStringContainsString('не больше 10', $this->adapter->validate(new PublishRequest('x', $many))[0]);

        $album = [$this->media(MediaKind::Image, 'a.jpg'), $this->media(MediaKind::Image, 'b.jpg')];
        self::assertStringContainsString('кнопки', $this->adapter->validate(new PublishRequest('x', $album, [['text' => 'a', 'url' => 'https://e.com']]))[0]);

        $mixed = [$this->media(MediaKind::Image, 'a.jpg'), $this->media(MediaKind::Document, 'b.pdf')];
        self::assertStringContainsString('Документы', $this->adapter->validate(new PublishRequest('x', $mixed))[0]);

        self::assertStringContainsString('http', $this->adapter->validate(new PublishRequest('x', [], [['text' => 'a', 'url' => 'javascript:alert(1)']]))[0]);
        self::assertStringContainsString('300', $this->adapter->validate(new PublishRequest('', poll: ['question' => str_repeat('?', 301), 'options' => ['a', 'b'], 'anonymous' => true, 'multiple' => false]))[0]);
    }

    public function testHtmlTagsDoNotCountTowardsTheLimit(): void
    {
        $text = '<b>' . str_repeat('a', 4000) . '</b><a href="https://example.com/' . str_repeat('x', 500) . '">link</a>';

        self::assertSame([], $this->adapter->validate(new PublishRequest($text, format: 'html')));
    }

    public function testHealthCheckReportsRights(): void
    {
        $this->expect('getChat', 'get_chat_channel');
        $this->expect('getChatMember', 'member_bot_admin_limited');

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertTrue($status->ok);
        self::assertSame(['post' => true, 'edit' => false, 'delete' => false, 'pin' => false], $status->rights);
        self::assertSame('Мой канал', $status->title);
    }

    public function testHealthCheckSaysWhenTheBotCannotPostAnyMore(): void
    {
        $this->expect('getChat', 'get_chat_channel');
        $this->expect('getChatMember', 'member_bot_no_post');

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertFalse($status->ok);
        self::assertFalse($status->transient);
        self::assertFalse($status->revoked);
        self::assertStringContainsString('Публикация сообщений', $status->message);
    }

    public function testHealthCheckOfAChannelTheBotWasKickedFromIsRevoked(): void
    {
        $this->expect('getChat', 'error_403_kicked', 403);

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertFalse($status->ok);
        self::assertTrue($status->revoked);
    }

    public function testHealthCheckDuringATelegramOutageIsInconclusive(): void
    {
        $this->expect('getChat', 'error_500', 502);

        $status = $this->adapter->healthCheck(self::CHAT, $this->credential);

        self::assertFalse($status->ok);
        self::assertTrue($status->transient, 'a passing outage must not mark the channel broken');
    }

    public function testConnectChecksTheTokenBeforeTheChannel(): void
    {
        $this->expect('getMe', 'error_401', 401);

        try {
            $this->adapter->connect($this->credential, '@mychannel');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringContainsString('Токен', $e->forUser());
        }
    }

    public function testConnectResolvesARealChannel(): void
    {
        $this->expect('getMe', 'get_me');
        $this->expect('getChat', 'get_chat_channel');
        $this->expect('getChatMember', 'member_bot_admin');

        $info = $this->adapter->connect($this->credential, 'https://t.me/mychannel');

        self::assertSame('-1001234567890', $info->externalId);
        self::assertSame('mychannel', $info->username);
        self::assertSame('channel', $info->kind);
        self::assertSame('AVATAR_SMALL', $info->avatarFileId);
        self::assertSame('@mychannel', $this->sent(1)['chat_id']);
        self::assertSame(987654321, $this->sent(2)['user_id']);
    }

    public function testConnectRejectsPrivateChatsAndInvitationLinks(): void
    {
        $this->expect('getMe', 'get_me');
        $this->expect('getChat', 'get_chat_private');
        try {
            $this->adapter->connect($this->credential, '@someone');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertStringContainsString('Это не канал', $e->forUser());
        }

        try {
            $this->adapter->connect($this->credential, 'https://t.me/+AbCdEf123456');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertStringContainsString('@mychannel', $e->forUser());
        }
    }

    public function testConnectNeedsPostingRightsInAChannelButOnlyAdminInAGroup(): void
    {
        $this->expect('getMe', 'get_me');
        $this->expect('getChat', 'get_chat_channel');
        $this->expect('getChatMember', 'member_plain');
        try {
            $this->adapter->connect($this->credential, '@mychannel');
            self::fail('PlatformError expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
        }

        $this->expect('getMe', 'get_me');
        $this->expect('getChat', 'get_chat_group');
        $this->expect('getChatMember', 'member_bot_group_admin');
        $info = $this->adapter->connect($this->credential, '-1005555555555');
        self::assertSame('group', $info->kind);
        self::assertTrue($info->rights['pin']);
    }

    public function testReferencesAreParsedStrictly(): void
    {
        self::assertSame('@mychannel', TelegramInspector::parseReference('mychannel'));
        self::assertSame('@mychannel', TelegramInspector::parseReference('  @mychannel '));
        self::assertSame('@mychannel', TelegramInspector::parseReference('t.me/mychannel'));
        self::assertSame('@mychannel', TelegramInspector::parseReference('https://telegram.me/mychannel/'));
        self::assertSame(-1001234567890, TelegramInspector::parseReference('-1001234567890'));
        self::assertNull(TelegramInspector::parseReference('https://t.me/+invite'));
        self::assertNull(TelegramInspector::parseReference('a b'));
        self::assertNull(TelegramInspector::parseReference('@ab'));
        self::assertNull(TelegramInspector::parseReference("@mychannel\n/evil"));
        self::assertNull(TelegramInspector::parseReference(''));
    }

    public function testBotIdComesFromTheTokenPrefix(): void
    {
        self::assertSame(987654321, TelegramInspector::botIdFromToken(self::TOKEN));
        self::assertNull(TelegramInspector::botIdFromToken('garbage'));
    }

    public function testCredentialsAreHiddenFromDumps(): void
    {
        self::assertStringNotContainsString('SHARED-bot-token', print_r($this->credential, true));
        self::assertStringNotContainsString('SHARED-bot-token', var_export($this->credential->__debugInfo(), true));
    }
}
