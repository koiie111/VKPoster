<?php

declare(strict_types=1);

namespace App\Tests\Unit\OAuth;

use App\Integrations\OAuth\OAuthException;
use App\Integrations\OAuth\TelegramLogin;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TelegramSigner;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramLogin::class)]
final class TelegramLoginTest extends TestCase
{
    private FakeClock $clock;
    private TelegramLogin $login;

    protected function setUp(): void
    {
        $this->clock = new FakeClock('2026-10-04 12:00:00');
        $this->login = new TelegramLogin($this->clock, TestEnv::TELEGRAM_TOKEN, 'ezposter_test_bot');
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function testValidDataGivesAProfile(): void
    {
        $profile = $this->login->verify(TelegramSigner::user($this->now() - 30));

        self::assertSame('telegram', $profile->provider);
        self::assertSame('777000', $profile->id);
        self::assertSame('Иван Петров', $profile->name);
        self::assertNull($profile->email);
        self::assertFalse($profile->emailVerified);
        self::assertNull($profile->trustedEmail());
        self::assertSame('https://t.me/i/userpic/320/x.jpg', $profile->avatar);
    }

    public function testFieldOrderDoesNotMatter(): void
    {
        $data = array_reverse(TelegramSigner::user($this->now()), true);

        self::assertSame('777000', $this->login->verify($data)->id);
    }

    public function testNameFallsBackToTheUsername(): void
    {
        $data = TelegramSigner::sign(['id' => '5', 'username' => 'only_user', 'auth_date' => (string) $this->now()]);

        self::assertSame('only_user', $this->login->verify($data)->name);
    }

    public function testTamperedFieldIsRejected(): void
    {
        $data = TelegramSigner::user($this->now());
        $data['id'] = '1';

        $this->expectException(OAuthException::class);
        $this->login->verify($data);
    }

    public function testExtraFieldBreaksTheSignature(): void
    {
        $data = TelegramSigner::user($this->now());
        $data['is_admin'] = '1';

        $this->expectException(OAuthException::class);
        $this->login->verify($data);
    }

    public function testSignatureMadeWithAnotherBotTokenIsRejected(): void
    {
        $data = TelegramSigner::sign(['id' => '777000', 'auth_date' => (string) $this->now()], '999:other-bot');

        $this->expectException(OAuthException::class);
        $this->login->verify($data);
    }

    public function testMissingOrMalformedHashIsRejected(): void
    {
        foreach ([null, '', 'zz', str_repeat('A', 64), str_repeat('a', 63)] as $hash) {
            $data = TelegramSigner::user($this->now());
            if ($hash === null) {
                unset($data['hash']);
            } else {
                $data['hash'] = $hash;
            }
            try {
                $this->login->verify($data);
                self::fail('accepted hash ' . var_export($hash, true));
            } catch (OAuthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOldAuthDateIsRejected(): void
    {
        $this->login->verify(TelegramSigner::user($this->now() - TelegramLogin::MAX_AGE));
        $this->expectException(OAuthException::class);
        $this->login->verify(TelegramSigner::user($this->now() - TelegramLogin::MAX_AGE - 1));
    }

    public function testAuthDateFromTheFutureIsRejected(): void
    {
        $this->expectException(OAuthException::class);
        $this->login->verify(TelegramSigner::user($this->now() + 3600));
    }

    public function testNonNumericIdOrDateIsRejected(): void
    {
        foreach ([['id' => 'abc', 'auth_date' => (string) $this->now()], ['id' => '5', 'auth_date' => 'yesterday'], ['auth_date' => (string) $this->now()]] as $fields) {
            try {
                $this->login->verify(TelegramSigner::sign($fields));
                self::fail('accepted ' . json_encode($fields));
            } catch (OAuthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNonStringFieldIsRejected(): void
    {
        $data = TelegramSigner::user($this->now());
        $data['id'] = ['1'];

        $this->expectException(OAuthException::class);
        $this->login->verify($data);
    }

    public function testInsecurePhotoUrlIsDropped(): void
    {
        $data = TelegramSigner::sign(['id' => '5', 'first_name' => 'A', 'photo_url' => 'http://insecure.example/x.jpg', 'auth_date' => (string) $this->now()]);

        self::assertNull($this->login->verify($data)->avatar);
    }

    public function testNotConfiguredWithoutTokenOrName(): void
    {
        self::assertFalse((new TelegramLogin($this->clock, '', 'bot'))->isConfigured());
        self::assertFalse((new TelegramLogin($this->clock, 'token', ''))->isConfigured());
        self::assertTrue($this->login->isConfigured());
        self::assertSame('ezposter_test_bot', $this->login->botName());
    }
}
