<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\PasswordService;
use App\Tests\Support\ArrayMailer;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Forgotten password and password change.
 */
#[CoversClass(PasswordService::class)]
final class PasswordResetTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'another-long-passphrase-9';

    private function requestLink(string $email = 'anna@example.com'): string
    {
        $this->post('/password/forgot', ['email' => $email]);
        $this->drainQueue();
        $mail = $this->mailer->lastTo($email);
        self::assertNotNull($mail);

        return ArrayMailer::path($mail);
    }

    public function testRequestGivesTheSameAnswerForKnownAndUnknownAddresses(): void
    {
        $this->createUser();

        $known = $this->post('/password/forgot', ['email' => 'anna@example.com']);
        $unknown = $this->post('/password/forgot', ['email' => 'ghost@example.com']);

        self::assertSame('/password/forgot/sent', $known->header('Location'));
        self::assertSame($known->header('Location'), $unknown->header('Location'));
        $this->drainQueue();
        self::assertCount(1, $this->mailer->to('anna@example.com'));
        self::assertSame([], $this->mailer->to('ghost@example.com'));
        self::assertStringContainsString('ghost@example.com', $this->follow($unknown)->body);
    }

    public function testFullFlowSetsNewPasswordAndEndsEverySession(): void
    {
        $user = $this->createUser();
        $this->signIn(); // browser 1 stays signed in while the reset happens in browser 2
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
        $browserOne = $this->useBrowser();
        $link = $this->requestLink();

        self::assertSame(200, $this->get($link)->status);
        $done = $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertSame('/login', $done->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
        $this->drainQueue();
        self::assertNotNull(array_values(array_filter($this->mailer->to('anna@example.com'), static fn ($m): bool => $m->subject === 'Пароль изменён'))[0] ?? null);
        self::assertNotNull($this->db->select('SELECT password_changed_at FROM users WHERE id = ?', [$user->id])[0]['password_changed_at']);

        $this->useBrowser($browserOne);
        self::assertSame('/login', $this->get('/app')->header('Location'), 'the other browser was signed out');
        $this->useBrowser();
        self::assertSame('/login', $this->signIn('anna@example.com', self::PASSWORD)->header('Location'), 'the old password is dead');
        self::assertSame('/app', $this->signIn('anna@example.com', self::NEW_PASSWORD)->header('Location'));
    }

    public function testLinkWorksOnlyOnce(): void
    {
        $this->createUser();
        $link = $this->requestLink();
        $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertSame(410, $this->get($link)->status);
        $second = $this->post($link, ['password' => 'yet-another-passphrase-1', 'password_confirmation' => 'yet-another-passphrase-1']);
        self::assertSame(410, $second->status);
    }

    public function testLinkExpiresAfterOneHour(): void
    {
        $this->createUser();
        $link = $this->requestLink();
        $this->clock->advance(3601);

        self::assertSame(410, $this->get($link)->status);
        self::assertSame(410, $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->status);
    }

    public function testNewRequestInvalidatesTheOlderLink(): void
    {
        $this->createUser();
        $first = $this->requestLink();
        $this->clock->advance(5);
        $second = $this->requestLink();

        self::assertNotSame($first, $second);
        self::assertSame(410, $this->get($first)->status);
        self::assertSame(200, $this->get($second)->status);
    }

    public function testTokenOfAnotherTypeIsRejected(): void
    {
        $this->createUser(verified: false);
        $this->post('/register', ['name' => 'Иван', 'email' => 'x@example.com', 'password' => 'a-long-unusual-passphrase', 'consent' => '1']);
        $this->drainQueue();
        $verifyPath = ArrayMailer::path($this->mailer->lastTo('x@example.com') ?? throw new \LogicException());
        $token = substr($verifyPath, strlen('/email/verify/'));

        self::assertSame(410, $this->get('/password/reset/' . $token)->status, 'a verification token must not reset a password');
        self::assertSame(410, $this->post('/password/reset/' . $token, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->status);
        self::assertSame(410, $this->get('/email/change/' . $token)->status);
    }

    public function testMalformedTokensDoNotEvenRoute(): void
    {
        self::assertSame(404, $this->get('/password/reset/short')->status);
        self::assertSame(404, $this->get('/email/verify/' . str_repeat('!', 43))->status);
    }

    public function testWeakNewPasswordIsRejectedWithoutBurningTheLink(): void
    {
        $this->createUser();
        $link = $this->requestLink();

        $weak = $this->post($link, ['password' => 'password123', 'password_confirmation' => 'password123']);
        self::assertSame($link, $weak->header('Location'));
        self::assertStringContainsString('слишком распространён', $this->text($this->follow($weak)));
        $mismatch = $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-one-12345']);
        self::assertSame($link, $mismatch->header('Location'));

        self::assertSame('/login', $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->header('Location'));
    }

    public function testResettingProvesMailboxOwnership(): void
    {
        $user = $this->createUser(verified: false);
        $link = $this->requestLink();

        $this->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertNotNull($this->db->select('SELECT email_verified_at FROM users WHERE id = ?', [$user->id])[0]['email_verified_at']);
    }

    public function testRequestsAreLimitedPerAddress(): void
    {
        $this->createUser();
        for ($i = 0; $i < 6; ++$i) {
            $this->post('/password/forgot', ['email' => 'anna@example.com']);
        }
        $this->drainQueue();

        self::assertCount(3, $this->mailer->to('anna@example.com'));
    }

    public function testBlockedAccountsGetNoResetMail(): void
    {
        $user = $this->createUser();
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', ['blocked', $user->id]);
        $this->post('/password/forgot', ['email' => 'anna@example.com']);
        $this->drainQueue();

        self::assertSame([], $this->mailer->sent);
    }

    public function testInvalidEmailFormatShowsAFieldError(): void
    {
        $response = $this->post('/password/forgot', ['email' => 'nonsense']);

        self::assertSame('/password/forgot', $response->header('Location'));
        self::assertStringContainsString('Почта', $this->follow($response)->body);
    }

    public function testChangePasswordRequiresCurrentPasswordAndKeepsThisDevice(): void
    {
        $this->createUser();
        $this->signIn();

        $wrong = $this->post('/account/password', ['current_password' => 'not my password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame('/account/security#password', $wrong->header('Location'));
        self::assertStringContainsString('Текущий пароль указан неверно', $this->follow($wrong)->body);

        $ok = $this->post('/account/password', ['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame('/account/security', $ok->header('Location'));
        self::assertSame(200, $this->getApp()->status, 'this device stays signed in');
        $this->drainQueue();
        self::assertSame('Пароль изменён', $this->mailer->lastTo('anna@example.com')?->subject);
    }

    public function testChangingThePasswordSignsOutOtherDevices(): void
    {
        $this->createUser();
        $this->signIn();
        $rows = $this->db->select('SELECT public_id FROM user_sessions');
        self::assertCount(1, $rows);
        // Register a second device directly, then change the password from the first.
        $registry = $this->app->container()->get(\App\Domain\Auth\SessionRegistry::class);
        $user = $this->db->select('SELECT id FROM users')[0];
        $registry->register((int) $user['id'], bin2hex(random_bytes(32)), '198.51.100.7', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');

        $this->post('/account/password', ['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
    }

    public function testCurrentPasswordGuessingIsLimited(): void
    {
        $this->createUser();
        $this->signIn();
        for ($i = 0; $i < 5; ++$i) {
            $this->post('/account/password', ['current_password' => 'guess ' . $i, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        }

        $blocked = $this->post('/account/password', ['current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertStringContainsString('Слишком много попыток', $this->follow($blocked)->body);
    }
}
