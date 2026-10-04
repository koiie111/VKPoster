<?php

declare(strict_types=1);

namespace App\Tests\Unit\OAuth;

use App\Integrations\OAuth\ProviderRegistry;
use App\Kernel\Exception\ConfigException;
use App\Tests\Support\FakeClock;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderRegistry::class)]
final class ProviderRegistryTest extends TestCase
{
    /**
     * @param array<string, string> $env
     */
    private function registry(array $env): ProviderRegistry
    {
        $base = ['DEV_OAUTH_FAKE' => '0', 'TELEGRAM_LOGIN_BOT_TOKEN' => '', 'TELEGRAM_LOGIN_BOT_NAME' => ''];

        return new ProviderRegistry(TestEnv::config($env + $base), new MockHttpClient(), new FakeClock('2026-10-04 12:00:00'));
    }

    public function testProvidersWithoutKeysAreHidden(): void
    {
        $registry = $this->registry([]);

        self::assertSame([], $registry->enabledIds());
        self::assertNull($registry->get('vkid'));
        self::assertNull($registry->telegram());
    }

    public function testEachProviderNeedsItsKeys(): void
    {
        self::assertSame(['vkid'], $this->registry(['VKID_CLIENT_ID' => '1'])->enabledIds());
        self::assertSame([], $this->registry(['YANDEX_CLIENT_ID' => '1'])->enabledIds(), 'the secret is required too');
        self::assertSame(['yandex'], $this->registry(['YANDEX_CLIENT_ID' => '1', 'YANDEX_CLIENT_SECRET' => 's'])->enabledIds());
        self::assertSame([], $this->registry(['GOOGLE_CLIENT_SECRET' => 's'])->enabledIds());
        self::assertSame(['google'], $this->registry(['GOOGLE_CLIENT_ID' => '1', 'GOOGLE_CLIENT_SECRET' => 's'])->enabledIds());
        self::assertSame([], $this->registry(['TELEGRAM_LOGIN_BOT_TOKEN' => 't'])->enabledIds(), 'the bot name is required too');
        self::assertSame(['telegram'], $this->registry(['TELEGRAM_LOGIN_BOT_TOKEN' => 't', 'TELEGRAM_LOGIN_BOT_NAME' => 'bot'])->enabledIds());
    }

    public function testButtonsFollowTheConfiguredOrder(): void
    {
        $env = [
            'VKID_CLIENT_ID' => '1', 'YANDEX_CLIENT_ID' => '1', 'YANDEX_CLIENT_SECRET' => 's', 'GOOGLE_CLIENT_ID' => '1', 'GOOGLE_CLIENT_SECRET' => 's',
            'TELEGRAM_LOGIN_BOT_TOKEN' => 't', 'TELEGRAM_LOGIN_BOT_NAME' => 'bot',
        ];

        self::assertSame(['vkid', 'yandex', 'telegram', 'google'], $this->registry($env)->enabledIds());
        self::assertSame(['google', 'telegram', 'vkid', 'yandex'], $this->registry($env + ['OAUTH_ORDER' => 'google,telegram,vkid,yandex'])->enabledIds());
        self::assertSame(['google', 'vkid', 'yandex', 'telegram'], $this->registry($env + ['OAUTH_ORDER' => 'google'])->enabledIds());
    }

    public function testOrderIgnoresDisabledProvidersAndUnknownNames(): void
    {
        $registry = $this->registry(['VKID_CLIENT_ID' => '1', 'GOOGLE_CLIENT_ID' => '1', 'GOOGLE_CLIENT_SECRET' => 's', 'OAUTH_ORDER' => 'telegram,nonsense,google,vkid']);

        self::assertSame(['google', 'vkid'], $registry->enabledIds());
    }

    public function testFakeProviderIsOnlyAvailableWhenSwitchedOnOutsideProduction(): void
    {
        self::assertNull($this->registry([])->get('fake'));
        self::assertNotNull($this->registry(['DEV_OAUTH_FAKE' => '1'])->get('fake'));
        self::assertSame('Тестовый вход', $this->registry(['DEV_OAUTH_FAKE' => '1'])->label('fake'));
    }

    public function testProductionRefusesToBootWithTheFakeProvider(): void
    {
        $this->expectException(ConfigException::class);
        TestEnv::config(['APP_ENV' => 'production', 'DEV_OAUTH_FAKE' => '1']);
    }

    public function testLabels(): void
    {
        $registry = $this->registry(['VKID_CLIENT_ID' => '1']);

        self::assertSame('VK ID', $registry->label('vkid'));
        self::assertSame('Telegram', $registry->label('telegram'));
        self::assertSame('whatever', $registry->label('whatever'));
    }
}
