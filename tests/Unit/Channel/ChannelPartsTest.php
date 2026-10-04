<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\ConnectCodes;
use App\Domain\Channel\CredentialVault;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Telegram\TelegramAdapter;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Integrations\Social\Telegram\TelegramInspector;
use App\Integrations\Social\Telegram\TelegramWebhook;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlatformRegistry::class)]
#[CoversClass(ConnectCodes::class)]
#[CoversClass(CredentialVault::class)]
#[CoversClass(TelegramWebhook::class)]
#[CoversClass(FakeAdapter::class)]
#[CoversClass(Channel::class)]
#[CoversClass(PlatformError::class)]
#[CoversClass(HealthStatus::class)]
#[CoversClass(Platform::class)]
#[CoversClass(ChannelStatus::class)]
final class ChannelPartsTest extends TestCase
{
    private function telegram(): TelegramAdapter
    {
        return new TelegramAdapter(new TelegramClientFactory(new MockHttpClient()), new TelegramInspector());
    }

    public function testRegistryOffersOnlyEnabledPlatformsInTheFlagOrder(): void
    {
        $registry = new PlatformRegistry([$this->telegram(), new FakeAdapter()], ['fake', 'telegram', 'vk', 'bogus'], true);

        self::assertSame([Platform::Fake, Platform::Telegram], $registry->enabled(), 'vk has no adapter yet, bogus is not a platform');
        self::assertTrue($registry->isEnabled(Platform::Telegram));
        self::assertFalse($registry->isEnabled(Platform::Vk));
        self::assertNotNull($registry->connector(Platform::Telegram));
        self::assertNull($registry->connector(Platform::Fake), 'the fake network has no token flow');
    }

    public function testTheFakePlatformIsIgnoredInProduction(): void
    {
        $registry = new PlatformRegistry([$this->telegram(), new FakeAdapter()], ['telegram', 'fake'], false);

        self::assertSame([Platform::Telegram], $registry->enabled());
        $this->expectException(\LogicException::class);
        $registry->adapter(Platform::Fake);
    }

    public function testASwitchedOffPlatformCannotBeUsed(): void
    {
        $registry = new PlatformRegistry([$this->telegram()], [], true);

        $this->expectException(\LogicException::class);
        $registry->adapter(Platform::Telegram);
    }

    public function testConnectCodesNormaliseWhatPeopleType(): void
    {
        self::assertSame('ABCDEFGHJK', ConnectCodes::normalize(' abcde-fghjk '));
        self::assertTrue(ConnectCodes::isWellFormed('ABCDEFGHJK'));
        self::assertFalse(ConnectCodes::isWellFormed('ABCDEFGHJ0'), 'zero is not in the alphabet');
        self::assertFalse(ConnectCodes::isWellFormed('ABC'));
        self::assertFalse(ConnectCodes::isWellFormed('ABCDEFGHJK1'));
        self::assertSame('ABCDE-FGHJK', ConnectCodes::pretty('ABCDEFGHJK'));
        self::assertSame(64, strlen(ConnectCodes::hash('ABCDEFGHJK')));
    }

    public function testTokensAreMaskedToTheirEnds(): void
    {
        self::assertSame('1234…WXYZ', CredentialVault::mask('123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        self::assertSame('••••••', CredentialVault::mask('abcdef'));
        self::assertStringNotContainsString('ABCDEFGHIJ', CredentialVault::mask('123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
    }

    public function testWebhookAuthenticityNeedsBothSecrets(): void
    {
        $secret = 'secret-value-0123456789';
        $header = TelegramWebhook::headerToken($secret);

        self::assertTrue(TelegramWebhook::isAuthentic($secret, $secret, $header));
        self::assertFalse(TelegramWebhook::isAuthentic($secret, $secret, null), 'no header');
        self::assertFalse(TelegramWebhook::isAuthentic($secret, $secret, 'wrong'));
        self::assertFalse(TelegramWebhook::isAuthentic($secret, 'other-secret-value-0123', $header));
        self::assertFalse(TelegramWebhook::isAuthentic('', '', TelegramWebhook::headerToken('')), 'unconfigured endpoint is closed');
        self::assertNotSame($secret, $header, 'the header token is derived, not the path secret itself');
        self::assertSame('https://example.com/webhooks/telegram/' . $secret, TelegramWebhook::url('https://example.com/', $secret));
    }

    public function testFakeAdapterRecordsPublicationsAndFailsOnDemand(): void
    {
        $fake = new FakeAdapter();
        $credential = new Credential(Platform::Fake, 'fake');

        $result = $fake->publish(new PublishRequest('Привет'), 'fake-1', $credential, 'k');
        self::assertSame('1', $result->externalId);
        self::assertSame('Привет', $fake->published[0]['text']);

        $fake->failNext(ErrorKind::RateLimited);
        try {
            $fake->publish(new PublishRequest('x'), 'fake-1', $credential, 'k');
            self::fail('scripted failure expected');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind);
        }
        self::assertSame('2', $fake->publish(new PublishRequest('x'), 'fake-1', $credential, 'k')->externalId, 'the failure happens once');

        $fake->delete($result, 'fake-1', $credential);
        self::assertSame(['1'], $fake->deleted);
        self::assertFalse($fake->healthCheck('broken-1', $credential)->ok);
        self::assertTrue($fake->healthCheck('fake-1', $credential)->ok);
    }

    public function testPlatformErrorsKnowWhetherTheyConcernTheChannel(): void
    {
        self::assertTrue((new PlatformError(ErrorKind::Auth, 'x'))->concernsChannel());
        self::assertTrue((new PlatformError(ErrorKind::Permanent, 'x'))->concernsChannel());
        self::assertFalse((new PlatformError(ErrorKind::Temporary, 'x'))->concernsChannel());
        self::assertFalse((new PlatformError(ErrorKind::RateLimited, 'x'))->concernsChannel());
        self::assertFalse((new PlatformError(ErrorKind::UnknownOutcome, 'x'))->concernsChannel());
        self::assertStringContainsString('Попробуйте позже', (new PlatformError(ErrorKind::Temporary, 'x'))->forUser());
    }

    public function testChannelLinksAndNames(): void
    {
        $channel = $this->channel('-1001234567890', 'mychannel', null);
        self::assertSame('https://t.me/mychannel/5', $channel->postUrl('5'));
        self::assertSame('@mychannel', $channel->handle());
        self::assertSame('Мой канал', $channel->displayName());

        $private = $this->channel('-1009876543210', null, 'Псевдоним');
        self::assertSame('https://t.me/c/9876543210/5', $private->postUrl('5'));
        self::assertSame('Закрытый канал', $private->handle());
        self::assertSame('Псевдоним', $private->displayName());
        self::assertSame(['post' => true, 'delete' => false], $private->rights());
    }

    public function testStatusesKnowWhenTheyNeedAttention(): void
    {
        self::assertTrue(ChannelStatus::Error->needsAttention());
        self::assertTrue(ChannelStatus::Revoked->needsAttention());
        self::assertFalse(ChannelStatus::Paused->needsAttention());
        self::assertFalse(ChannelStatus::Active->needsAttention());
        self::assertSame('Подключён', ChannelStatus::Active->label());
        self::assertSame('Telegram', Platform::Telegram->label());
        self::assertSame('send', Platform::Telegram->icon());
    }

    private function channel(string $externalId, ?string $username, ?string $alias): Channel
    {
        return new Channel(1, 'ULID', 1, Platform::Telegram, $externalId, ChannelMode::SharedBot, 'Мой канал', $alias, $username, 'channel', null, ChannelStatus::Active, null, ['rights' => ['post' => true, 'delete' => false]], null, null, new \DateTimeImmutable());
    }

    public function testEveryPlatformAndStatusHasALabelAndAnIcon(): void
    {
        foreach (Platform::cases() as $platform) {
            self::assertNotSame('', $platform->label(), $platform->value);
            self::assertNotSame('', $platform->icon(), $platform->value);
        }
        foreach (ChannelStatus::cases() as $status) {
            self::assertNotSame('', $status->label(), $status->value);
        }
        self::assertSame('Нужно проверить', ChannelStatus::Error->label());
        self::assertSame('На паузе', ChannelStatus::Paused->label());
        self::assertSame('Нужно переподключить', ChannelStatus::Revoked->label());
    }

    public function testHealthStatusFactories(): void
    {
        $broken = HealthStatus::broken('Бота удалили', true);
        self::assertFalse($broken->ok);
        self::assertTrue($broken->revoked);
        self::assertFalse($broken->transient);

        $unknown = HealthStatus::unknown('Сеть недоступна');
        self::assertFalse($unknown->ok);
        self::assertTrue($unknown->transient);

        $ok = HealthStatus::ok(['post' => true], 'Канал');
        self::assertTrue($ok->ok);
        self::assertSame('Канал', $ok->title);
    }
}
