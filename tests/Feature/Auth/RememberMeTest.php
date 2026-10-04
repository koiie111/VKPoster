<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\RememberMe;
use App\Http\Middleware\RememberLogin;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "Remember me": cookie format, rotation, theft detection, revocation.
 */
#[CoversClass(RememberMe::class)]
#[CoversClass(RememberLogin::class)]
final class RememberMeTest extends AuthTestCase
{
    private function rememberCookie(): string
    {
        $value = $this->cookie('remember');
        self::assertNotNull($value);

        return $value;
    }

    public function testCookieIsOnlyIssuedWhenAsked(): void
    {
        $this->createUser();

        $this->signIn();
        self::assertNull($this->cookie('remember'));
        $this->post('/logout');

        $response = $this->signIn(remember: true);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}:[A-Za-z0-9_-]{43}$/', $this->rememberCookie());
        $set = implode("\n", $response->cookies);
        self::assertStringContainsString('Max-Age=' . (30 * 86400), $set);
        self::assertStringContainsString('HttpOnly', $set);
        self::assertStringContainsString('SameSite=Lax', $set);
    }

    public function testOnlyAHashOfTheValidatorIsStored(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        [$selector, $validator] = explode(':', $this->rememberCookie());

        $row = $this->db->select('SELECT selector, token_hash FROM auth_tokens WHERE type = ?', ['remember'])[0];

        self::assertSame($selector, $row['selector']);
        self::assertSame(hash('sha256', $validator), $row['token_hash']);
        self::assertStringNotContainsString($validator, json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testCookieSignsTheUserInWithoutASessionAndIsRotated(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $first = $this->rememberCookie();
        $this->useBrowser(['remember' => $first]); // the session cookie is gone (browser restarted)

        $app = $this->get('/app');

        self::assertSame(200, $app->status);
        $second = $this->rememberCookie();
        self::assertNotSame($first, $second);
        self::assertSame(explode(':', $first)[0], explode(':', $second)[0], 'same device keeps its selector');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions')[0]['c']);
    }

    public function testReplayingAnOldValidatorRevokesEverything(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $stolen = $this->rememberCookie();
        $this->useBrowser(['remember' => $stolen]);
        $this->get('/app'); // the real owner comes back: validator rotates
        $current = $this->rememberCookie();
        $this->clock->advance(RememberMe::GRACE_SECONDS + 5);

        // The thief replays the cookie they copied earlier.
        $this->useBrowser(['remember' => $stolen]);
        $thief = $this->get('/app');

        self::assertSame('/login', $thief->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM auth_tokens WHERE type = ?', ['remember'])[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
        // The legitimate cookie is dead too: the owner has to sign in again.
        $this->useBrowser(['remember' => $current]);
        self::assertSame('/login', $this->get('/app')->header('Location'));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM audit_log WHERE action = ?', ['auth.remember.theft_detected'])[0]['c']);
    }

    public function testParallelRequestsWithTheSameCookieAreNotTreatedAsTheft(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $cookie = $this->rememberCookie();

        $this->useBrowser(['remember' => $cookie]);
        self::assertSame(200, $this->get('/app')->status);
        $this->clock->advance(5);
        $this->useBrowser(['remember' => $cookie]);
        $second = $this->get('/app');

        self::assertSame(200, $second->status, 'the previous validator stays valid for a minute');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM auth_tokens WHERE type = ?', ['remember'])[0]['c']);
    }

    public function testLogoutForgetsTheDevice(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $cookie = $this->rememberCookie();

        $out = $this->post('/logout');

        self::assertStringContainsString('Max-Age=0', implode("\n", $out->cookies));
        $this->useBrowser(['remember' => $cookie]);
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testChangingThePasswordRevokesRememberTokens(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $cookie = $this->rememberCookie();

        $this->post('/account/password', ['current_password' => self::PASSWORD, 'password' => 'brand-new-passphrase-77', 'password_confirmation' => 'brand-new-passphrase-77']);

        $this->useBrowser(['remember' => $cookie]);
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testCookieExpiresAfterThirtyDays(): void
    {
        $this->createUser();
        $this->signIn(remember: true);
        $cookie = $this->rememberCookie();
        $this->clock->advance(30 * 86400 + 10);

        $this->useBrowser(['remember' => $cookie]);

        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testGarbageCookiesAreIgnoredAndCleared(): void
    {
        $this->useBrowser(['remember' => 'garbage']);
        $response = $this->get('/login');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Max-Age=0', implode("\n", $response->cookies));
    }

    public function testRememberedLoginSkipsTheSecondFactorOnlyBecauseItWasPassedWhenIssued(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn(remember: true);
        self::assertNull($this->cookie('remember'), 'no cookie before the second factor is passed');
        $this->post('/login/2fa', ['code' => $this->totpCode($user->id)]);

        self::assertNotNull($this->cookie('remember'));
    }
}
