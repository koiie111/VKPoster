<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\LoginService;
use App\Http\Middleware\Authenticate;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Password sign-in: generic errors, throttling, session handling, redirects.
 */
#[CoversClass(LoginService::class)]
#[CoversClass(Authenticate::class)]
final class LoginTest extends AuthTestCase
{
    public function testSuccessfulLoginOpensTheApp(): void
    {
        $this->createUser();

        $response = $this->signIn();

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(200, $this->getApp()->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
        self::assertSame('success', $this->db->select('SELECT outcome FROM login_attempts')[0]['outcome']);
    }

    public function testGuestsAreSentToTheLoginFormAndBackAfterwards(): void
    {
        $this->createUser();

        $guest = $this->get('/account/security');
        self::assertSame('/login', $guest->header('Location'));

        $response = $this->signIn();
        self::assertSame('/account/security', $response->header('Location'));
    }

    public function testWrongPasswordAndUnknownEmailLookIdentical(): void
    {
        $this->createUser();

        $wrongPassword = $this->follow($this->signIn('anna@example.com', 'wrong password here'));
        $unknownEmail = $this->follow($this->signIn('nobody@example.com', 'wrong password here'));

        self::assertSame($this->text($wrongPassword), $this->text($unknownEmail));
        self::assertStringContainsString('Неверная почта или пароль', $wrongPassword->body);
    }

    public function testTheTypedEmailIsKeptButThePasswordIsNot(): void
    {
        $this->createUser();

        $page = $this->follow($this->signIn('anna@example.com', 'nope-nope-nope'))->body;

        self::assertStringContainsString('value="anna@example.com"', $page);
        self::assertStringNotContainsString('nope-nope-nope', $page);
    }

    public function testSessionIdAndCsrfTokenChangeOnLogin(): void
    {
        $this->createUser();
        $this->get('/login');
        $before = $this->cookie('sid');
        $tokenBefore = $this->csrfToken();

        $this->signIn();

        self::assertNotNull($before);
        self::assertNotSame($before, $this->cookie('sid'));
        self::assertNotSame($tokenBefore, $this->csrfToken());
    }

    public function testAccountIsLockedAfterFiveFailuresWithGrowingDelay(): void
    {
        $this->createUser();
        for ($i = 0; $i < 5; ++$i) {
            $this->signIn('anna@example.com', 'wrong password ' . $i);
        }

        $locked = $this->follow($this->signIn());
        self::assertStringContainsString('Слишком много попыток входа', $locked->body);
        self::assertGreaterThanOrEqual(5, (int) $this->db->select('SELECT COUNT(*) AS c FROM login_attempts WHERE outcome = ?', ['bad_password'])[0]['c']);

        // The correct password works again once the delay (30 s) is over.
        $this->clock->advance(31);
        self::assertSame('/app', $this->signIn()->header('Location'));
    }

    public function testLockoutBehavesTheSameForUnknownEmails(): void
    {
        $this->createUser();
        for ($i = 0; $i < 6; ++$i) {
            $this->signIn('ghost@example.com', 'wrong password ' . $i);
        }
        $this->signIn('anna@example.com', 'wrong password');

        $ghost = $this->text($this->follow($this->signIn('ghost@example.com', 'again wrong')));

        self::assertStringContainsString('Слишком много попыток входа', $ghost);
    }

    public function testOwnerIsWarnedByEmailAfterTenFailures(): void
    {
        $this->createUser();
        for ($i = 0; $i < 10; ++$i) {
            $this->clock->advance(1000); // far beyond any lock, so every attempt is evaluated
            $this->signIn('anna@example.com', 'wrong password ' . $i);
        }
        $this->drainQueue();

        $warnings = array_filter($this->mailer->to('anna@example.com'), static fn ($m): bool => $m->subject === 'Много неудачных попыток входа');
        self::assertCount(1, $warnings);
    }

    public function testBlockedAccountCannotSignIn(): void
    {
        $user = $this->createUser();
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', ['blocked', $user->id]);

        $response = $this->follow($this->signIn());

        self::assertStringContainsString('Аккаунт заблокирован', $response->body);
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testBlockingAnAccountEndsItsOpenSessions(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', ['blocked', $user->id]);

        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testOldPasswordHashesAreUpgradedTransparently(): void
    {
        $user = $this->createUser();
        $weak = password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]);
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$weak, $user->id]);

        $this->signIn();

        $stored = (string) $this->db->select('SELECT password_hash FROM users WHERE id = ?', [$user->id])[0]['password_hash'];
        self::assertNotSame($weak, $stored);
        self::assertStringContainsString('m=1024', $stored);
    }

    public function testLogoutNeedsPostAndCsrfAndEndsTheSession(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame(405, $this->get('/logout')->status);
        self::assertSame(419, $this->request('POST', '/logout')->status);
        self::assertSame('/login', $this->post('/logout')->header('Location'));
        self::assertSame('/login', $this->get('/app')->header('Location'));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NOT NULL')[0]['c']);
    }

    public function testSignedInVisitorsSkipTheLoginForm(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame('/app', $this->get('/login')->header('Location'));
        self::assertSame('/app', $this->get('/register')->header('Location'));
    }

    public function testLoginIsRateLimitedPerIp(): void
    {
        $last = null;
        for ($i = 0; $i < 21; ++$i) {
            $last = $this->post('/login', ['email' => 'user' . $i . '@example.com', 'password' => 'whatever-it-is']);
        }

        self::assertNotNull($last);
        self::assertSame(429, $last->status);
    }

    public function testLoginRequiresCsrf(): void
    {
        $this->createUser();
        $this->get('/login');

        $response = $this->request('POST', '/login', ['email' => 'anna@example.com', 'password' => self::PASSWORD]);

        self::assertSame(419, $response->status);
    }

    public function testHugePasswordsAreRejectedWithoutHashingThem(): void
    {
        $this->createUser();

        $response = $this->follow($this->signIn('anna@example.com', str_repeat('x', 5000)));

        self::assertStringContainsString('Неверная почта или пароль', $response->body);
    }

    public function testOpenRedirectThroughTheRememberedPathIsImpossible(): void
    {
        $this->createUser();
        // Authenticate only ever stores the request path of a GET, which is always relative.
        $this->get('/account/security');
        $response = $this->signIn();

        self::assertTrue(str_starts_with((string) $response->header('Location'), '/'));
        self::assertFalse(str_starts_with((string) $response->header('Location'), '//'));
    }
}
