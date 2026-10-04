<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Tests\Support\SocialTestCase;
use App\Tests\Support\TelegramSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Http\Controllers\Auth\SocialController;

/**
 * Telegram Login Widget callback: signed data, the page-issued `state`, single use, and linking.
 */
#[CoversClass(SocialController::class)]
final class TelegramLoginTest extends SocialTestCase
{
    /**
     * Render the page that holds the widget and return the callback URL it carries (with our `state`).
     */
    private function widgetState(string $page = '/login'): string
    {
        $html = $this->get($page)->body;
        self::assertSame(1, preg_match('/data-auth-url="http:\/\/localhost\/auth\/telegram\/callback\?state=([A-Za-z0-9_-]+)"/', $html, $m), 'widget auth url on ' . $page);

        return $m[1] ?? '';
    }

    /**
     * @param array<string, string> $data
     */
    private function telegramCallback(string $state, array $data): \App\Kernel\Http\Response
    {
        return $this->get('/auth/telegram/callback?' . http_build_query(['state' => $state] + $data));
    }

    /**
     * @return array<string, string>
     */
    private function fresh(): array
    {
        return TelegramSigner::user($this->clock->now()->getTimestamp() - 5);
    }

    public function testNewVisitorSignsUpWithTelegram(): void
    {
        $response = $this->telegramCallback($this->widgetState(), $this->fresh());

        self::assertSame('/auth/social/consent', $response->header('Location'));
        $done = $this->consent();
        self::assertSame('/app', $done->header('Location'));
        $user = $this->db->select('SELECT * FROM users')[0];
        self::assertNull($user['email']);
        self::assertSame(['telegram'], $this->linkedProviders((int) $user['id']));
        self::assertSame('777000', $this->db->select('SELECT provider_user_id FROM user_identities')[0]['provider_user_id']);
    }

    public function testKnownTelegramAccountSignsInDirectly(): void
    {
        $this->telegramCallback($this->widgetState(), $this->fresh());
        $this->consent();
        $this->post('/logout');

        $response = $this->telegramCallback($this->widgetState(), TelegramSigner::user($this->clock->now()->getTimestamp() - 1));

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(1, $this->userCount());
    }

    public function testBadSignatureIsRefused(): void
    {
        $data = $this->fresh();
        $data['id'] = '1';

        $response = $this->telegramCallback($this->widgetState(), $data);

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('Не удалось войти через Telegram', $this->follow($response)->body);
        self::assertSame(0, $this->userCount());
    }

    public function testOldAuthDateIsRefused(): void
    {
        $data = TelegramSigner::user($this->clock->now()->getTimestamp() - 86400 - 10);

        $response = $this->telegramCallback($this->widgetState(), $data);

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testStateIsRequired(): void
    {
        $this->widgetState();

        foreach (['', 'forged'] as $state) {
            $response = $this->telegramCallback($state, $this->fresh());
            self::assertSame('/login', $response->header('Location'));
            self::assertStringContainsString('устарела', $this->follow($response)->body);
        }
        self::assertSame(0, $this->userCount());
    }

    public function testCallbackWithoutVisitingThePageFirstIsRefused(): void
    {
        $response = $this->get('/auth/telegram/callback?' . http_build_query($this->fresh()));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testAnotherBrowsersStateIsUseless(): void
    {
        $state = $this->widgetState();
        $this->useBrowser();

        $response = $this->telegramCallback($state, $this->fresh());

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testACapturedSignedLinkCannotBeUsedTwice(): void
    {
        $data = $this->fresh();
        $first = $this->telegramCallback($this->widgetState(), $data);
        self::assertSame('/auth/social/consent', $first->header('Location'));
        $this->consent();
        $this->post('/logout');

        // The attacker has the URL's signed fields but must also obtain a fresh state: with it, the hash is still burnt.
        $replay = $this->telegramCallback($this->widgetState(), $data);

        self::assertSame('/login', $replay->header('Location'));
        self::assertStringContainsString('уже использована', $this->follow($replay)->body);
        self::assertSame(302, $this->get('/app')->status);
    }

    public function testStateCannotBeReused(): void
    {
        $state = $this->widgetState();
        $this->telegramCallback($state, $this->fresh());

        $second = $this->telegramCallback($state, TelegramSigner::user($this->clock->now()->getTimestamp() - 2, '888'));

        self::assertSame('/login', $second->header('Location'));
    }

    public function testSignedInUserLinksTelegram(): void
    {
        $user = $this->createUser();
        $this->signIn();

        $response = $this->telegramCallback($this->widgetState('/account/login-methods'), $this->fresh());

        self::assertSame('/account/login-methods', $response->header('Location'));
        self::assertSame(['telegram'], $this->linkedProviders($user->id));
    }

    public function testLinkStateFromTheLoginPageDoesNotLinkAnything(): void
    {
        // The login page issues a "login" state; a signed-in browser must not be tricked into linking with it.
        $state = $this->widgetState();
        $this->createUser();
        $this->signIn();

        $response = $this->telegramCallback($state, $this->fresh());

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testTelegramIsHiddenWithoutABotName(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['TELEGRAM_LOGIN_BOT_NAME' => '']);

        self::assertStringNotContainsString('telegram-widget.js', $this->get('/login')->body);
        self::assertSame(404, $this->get('/auth/telegram/callback?x=1')->status);
    }
}
