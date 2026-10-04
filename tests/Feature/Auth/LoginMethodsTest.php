<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\User\UserRepository;
use App\Http\Controllers\Account\LoginMethodsController;
use App\Tests\Support\SocialTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The "sign-in methods" page: linking, unlinking, the last-method rule and the first password of a
 * social account.
 */
#[CoversClass(LoginMethodsController::class)]
final class LoginMethodsTest extends SocialTestCase
{
    public function testGuestsAreSentToTheLoginForm(): void
    {
        self::assertSame('/login', $this->get('/account/login-methods')->header('Location'));
        self::assertSame('/login', $this->post('/account/login-methods/fake/link')->header('Location'));
        self::assertSame('/login', $this->post('/account/login-methods/fake/unlink')->header('Location'));
        self::assertSame('/login', $this->post('/account/login-methods/password', ['password' => 'x'])->header('Location'));
    }

    public function testPageListsProvidersAndTheirState(): void
    {
        $this->createUser();
        $this->signIn();

        $page = $this->get('/account/login-methods');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Тестовый вход', $page->body);
        self::assertStringContainsString('Не привязан', $page->body);
        self::assertStringContainsString('data-telegram-login="ezposter_test_bot"', $page->body);
        self::assertStringContainsString('https://telegram.org', (string) $page->header('Content-Security-Policy'));
    }

    public function testSignedInUserLinksAProvider(): void
    {
        $user = $this->createUser();
        $this->signIn();

        $response = $this->answer($this->startLink(), $this->profile('fake-77'));

        self::assertSame('/account/login-methods', $response->header('Location'));
        self::assertSame(['fake'], $this->linkedProviders($user->id));
        self::assertStringContainsString('привязан', $this->follow($response)->body);

        // And it works for signing in from a clean browser.
        $this->post('/logout');
        self::assertSame('/app', $this->socialLogin($this->profile('fake-77'))->header('Location'));
        self::assertSame(1, $this->userCount());
    }

    public function testLinkingAnAccountThatBelongsToSomeoneElseIsRefused(): void
    {
        $this->registerViaSocial($this->profile('fake-1'));
        $this->post('/logout');
        $mine = $this->createUser('anna@example.com');
        $this->signIn();

        $response = $this->answer($this->startLink(), $this->profile('fake-1'));

        self::assertStringContainsString('уже привязан к другому аккаунту', $this->follow($response)->body);
        self::assertSame([], $this->linkedProviders($mine->id));
    }

    public function testASecondAccountOfTheSameProviderIsRefused(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->answer($this->startLink(), $this->profile('fake-1'));

        $response = $this->answer($this->startLink(), $this->profile('fake-2'));

        self::assertStringContainsString('уже привязан другой профиль', $this->follow($response)->body);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities WHERE user_id = ?', [$user->id])[0]['c']);
    }

    public function testLinkingTheSameAccountTwiceIsHarmless(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->answer($this->startLink(), $this->profile('fake-1'));

        $response = $this->answer($this->startLink(), $this->profile('fake-1'));

        self::assertStringContainsString('уже привязан к вашему аккаунту', $this->follow($response)->body);
        self::assertSame(['fake'], $this->linkedProviders($user->id));
    }

    public function testALinkFlowStartedByAnotherUserCannotBeUsed(): void
    {
        $this->createUser('anna@example.com');
        $this->createUser('boris@example.com');
        $this->signIn('anna@example.com');
        $flow = $this->startLink();
        $annaJar = $this->useBrowser();
        $this->signIn('boris@example.com');
        // Boris's browser gets Anna's state: it is not in his session, so nothing is linked.
        $response = $this->answer($flow, $this->profile('fake-1'));
        $this->useBrowser($annaJar);

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testLinkingNeedsASignedInSessionAtCallbackTime(): void
    {
        $this->createUser();
        $this->signIn();
        $flow = $this->startLink();
        $this->post('/logout');
        // Start over the same session cookie: the flow is gone with the old session.
        $response = $this->answer($flow, $this->profile('fake-1'));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testTheOnlyWayToSignInCannotBeUnlinked(): void
    {
        $this->registerViaSocial($this->profile());
        $page = $this->get('/account/login-methods');
        self::assertStringContainsString('Единственный способ входа', $page->body);
        self::assertStringNotContainsString('data-dialog-open="unlink-fake"', $page->body);

        $response = $this->post('/account/login-methods/fake/unlink');

        self::assertStringContainsString('последний способ входа', $this->follow($response)->body);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testAccountWithAPasswordMayUnlinkItsProvider(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->answer($this->startLink(), $this->profile());
        self::assertStringContainsString('data-dialog-open="unlink-fake"', $this->get('/account/login-methods')->body);

        $response = $this->post('/account/login-methods/fake/unlink');

        self::assertStringContainsString('отвязан', $this->follow($response)->body);
        self::assertSame([], $this->linkedProviders($user->id));
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'auth.social.unlinked'")[0]['c']);
    }

    public function testPasswordDoesNotCountAsAMethodWithoutAnEmail(): void
    {
        $this->registerViaSocial($this->profile(email: null));
        $this->db->execute("UPDATE users SET password_hash = 'x'");

        $response = $this->post('/account/login-methods/fake/unlink');

        self::assertStringContainsString('последний способ входа', $this->follow($response)->body);
    }

    public function testUnlinkingSomethingNotLinkedSaysSo(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertStringContainsString('уже отвязан', $this->follow($this->post('/account/login-methods/fake/unlink'))->body);
    }

    public function testAnotherUsersIdentityIsUntouched(): void
    {
        $this->registerViaSocial($this->profile());
        $this->post('/logout');
        $this->createUser('anna@example.com');
        $this->signIn();

        $this->post('/account/login-methods/fake/unlink');

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testStartingALinkWithAnUnknownProviderIsA404(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame(404, $this->post('/account/login-methods/vkid/link')->status);
        self::assertSame('/account/login-methods', $this->post('/account/login-methods/telegram/link')->header('Location'));
    }

    // --- first password for a social account -------------------------------------------------------

    public function testSocialAccountWithAnEmailSetsAPasswordAndSignsInWithIt(): void
    {
        $this->registerViaSocial($this->profile());

        $response = $this->post('/account/login-methods/password', ['password' => 'a fine long passphrase', 'password_confirmation' => 'a fine long passphrase']);

        self::assertStringContainsString('Пароль задан', $this->follow($response)->body);
        $this->drainQueue();
        self::assertCount(1, $this->mailer->sent, 'the owner is told about the new password');
        $this->post('/logout');
        self::assertSame('/app', $this->signIn('ivan@example.com', 'a fine long passphrase')->header('Location'));
    }

    public function testSocialAccountWithoutAnEmailMustProvideOneAndConfirmIt(): void
    {
        $this->registerViaSocial($this->profile(email: null));
        $id = (int) $this->db->select('SELECT id FROM users')[0]['id'];

        $missing = $this->post('/account/login-methods/password', ['password' => 'a fine long passphrase', 'password_confirmation' => 'a fine long passphrase']);
        self::assertNull($this->db->select('SELECT password_hash FROM users')[0]['password_hash']);
        self::assertSame('/account/login-methods#password', $missing->header('Location'));

        $ok = $this->post('/account/login-methods/password', ['email' => 'Ivan@Example.com', 'password' => 'a fine long passphrase', 'password_confirmation' => 'a fine long passphrase']);
        self::assertSame('/account/login-methods', $ok->header('Location'));
        self::assertNotNull($this->db->select('SELECT password_hash FROM users')[0]['password_hash']);
        self::assertNull($this->db->select('SELECT email FROM users')[0]['email'], 'the address counts only after the link is opened');

        $this->drainQueue();
        $confirm = null;
        foreach ($this->mailer->sent as $mail) {
            if (preg_match('#/email/change/([A-Za-z0-9_-]{43})#', $mail->text . $mail->html, $m) === 1) {
                $confirm = $m[0];
            }
        }
        self::assertNotNull($confirm, 'a confirmation link was mailed');
        self::assertSame(200, $this->get($confirm)->status);
        $this->post($confirm);

        $row = $this->db->select('SELECT email, email_verified_at FROM users WHERE id = ?', [$id])[0];
        self::assertSame('ivan@example.com', $row['email']);
        self::assertNotNull($row['email_verified_at']);
        $this->post('/logout');
        self::assertSame('/app', $this->signIn('ivan@example.com', 'a fine long passphrase')->header('Location'));
    }

    public function testWeakOrMismatchedPasswordIsRejected(): void
    {
        $this->registerViaSocial($this->profile());

        $weak = $this->post('/account/login-methods/password', ['password' => 'short', 'password_confirmation' => 'short']);
        $mismatch = $this->post('/account/login-methods/password', ['password' => 'a fine long passphrase', 'password_confirmation' => 'something else entirely']);

        self::assertSame('/account/login-methods#password', $weak->header('Location'));
        self::assertSame('/account/login-methods#password', $mismatch->header('Location'));
        self::assertNull($this->db->select('SELECT password_hash FROM users')[0]['password_hash']);
    }

    public function testSettingAPasswordNeedsTheSecondFactorWhenItIsOn(): void
    {
        $this->registerViaSocial($this->profile());
        $users = $this->app->container()->get(UserRepository::class);
        $account = $users->find((int) $this->db->select('SELECT id FROM users')[0]['id']);
        self::assertNotNull($account);
        $this->enableTwoFactor($account);
        $body = ['password' => 'a fine long passphrase', 'password_confirmation' => 'a fine long passphrase'];

        $this->post('/account/login-methods/password', $body + ['code' => '000000']);
        self::assertNull($this->db->select('SELECT password_hash FROM users')[0]['password_hash']);

        $this->post('/account/login-methods/password', $body + ['code' => $this->totpCode($account->id)]);
        self::assertNotNull($this->db->select('SELECT password_hash FROM users')[0]['password_hash']);
    }

    public function testAccountThatAlreadyHasAPasswordCannotUseTheFirstPasswordForm(): void
    {
        $this->createUser();
        $this->signIn();
        $before = $this->db->select('SELECT password_hash FROM users')[0]['password_hash'];

        $response = $this->post('/account/login-methods/password', ['password' => 'a fine long passphrase', 'password_confirmation' => 'a fine long passphrase']);

        self::assertSame('/account/security#password', $response->header('Location'));
        self::assertSame($before, $this->db->select('SELECT password_hash FROM users')[0]['password_hash']);
    }

    // --- the rest of the account area for passwordless accounts --------------------------------------

    public function testSecurityPageWorksWithoutAPassword(): void
    {
        $this->registerViaSocial($this->profile(email: null));

        $page = $this->get('/account/security');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Пароль не задан', $page->body);
        self::assertStringContainsString('Почта не указана', $page->body);
        self::assertSame(200, $this->get('/account/login-methods')->status);
    }

    public function testTwoFactorCanBeManagedByCodeAloneWithoutAPassword(): void
    {
        $this->registerViaSocial($this->profile());
        $users = $this->app->container()->get(UserRepository::class);
        $account = $users->find((int) $this->db->select('SELECT id FROM users')[0]['id']);
        self::assertNotNull($account);
        $this->enableTwoFactor($account);

        $page = $this->get('/account/security')->body;
        self::assertStringContainsString('name="codes_code"', $page);
        self::assertStringNotContainsString('name="codes_password"', $page);
        $regenerate = $this->post('/account/2fa/recovery-codes', ['codes_code' => $this->totpCode($account->id)]);
        self::assertSame(200, $regenerate->status);

        $this->clock->advance(31);
        $disable = $this->post('/account/2fa/disable', ['disable_code' => $this->totpCode($account->id)]);
        self::assertSame('/account/security', $disable->header('Location'));
        $fresh = $users->find($account->id);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->hasTwoFactor());
    }

    public function testRegeneratingCodesWithoutAPasswordNeedsAValidCode(): void
    {
        $this->registerViaSocial($this->profile());
        $users = $this->app->container()->get(UserRepository::class);
        $account = $users->find((int) $this->db->select('SELECT id FROM users')[0]['id']);
        self::assertNotNull($account);
        $this->enableTwoFactor($account);

        $response = $this->post('/account/2fa/recovery-codes', ['codes_code' => '000000']);

        self::assertSame('/account/security#two-factor', $response->header('Location'));
    }
}
