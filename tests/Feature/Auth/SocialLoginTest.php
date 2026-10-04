<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\Social\SocialAuthService;
use App\Http\Auth\SocialFlow;
use App\Http\Controllers\Auth\SocialController;
use App\Tests\Support\SocialTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Social sign-in through the fake provider: state and PKCE checks, account matching rules 1-4, sign-up
 * with consent, blocked users, second factor and redirect safety.
 */
#[CoversClass(SocialController::class)]
#[CoversClass(SocialFlow::class)]
#[CoversClass(SocialAuthService::class)]
final class SocialLoginTest extends SocialTestCase
{
    public function testSignInPageOffersTheProvidersAndLoadsTheTelegramWidgetOnlyThere(): void
    {
        $login = $this->get('/login');
        self::assertStringContainsString('href="/auth/fake/redirect"', $login->body);
        self::assertStringContainsString('data-telegram-login="ezposter_test_bot"', $login->body);
        self::assertStringContainsString('https://telegram.org', (string) $login->header('Content-Security-Policy'));
        self::assertStringContainsString("frame-src https://oauth.telegram.org", (string) $login->header('Content-Security-Policy'));

        foreach (['/register', '/password/forgot'] as $path) {
            $page = $this->get($path);
            self::assertStringNotContainsString('telegram.org', (string) $page->header('Content-Security-Policy'), $path);
            self::assertStringNotContainsString('telegram-widget.js', $page->body, $path);
        }
        self::assertStringContainsString('href="/auth/fake/redirect"', $this->get('/register')->body);
    }

    public function testNewVisitorCreatesAnAccountAfterConsent(): void
    {
        $callback = $this->socialLogin($this->profile());

        self::assertSame('/auth/social/consent', $callback->header('Location'));
        self::assertSame(0, $this->userCount(), 'nothing is created before consent');
        $page = $this->get('/auth/social/consent');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('ivan@example.com', $page->body);

        $done = $this->consent('Иван');

        self::assertSame('/app', $done->header('Location'));
        self::assertSame(200, $this->get('/app')->status);
        $user = $this->db->select('SELECT * FROM users')[0];
        self::assertSame('ivan@example.com', $user['email']);
        self::assertNotNull($user['email_verified_at'], 'a provider-verified email is trusted');
        self::assertNull($user['password_hash']);
        self::assertSame('Иван', $user['name']);
        self::assertNotNull($user['consent_at']);
        self::assertSame(['fake'], $this->linkedProviders((int) $user['id']));
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'auth.social.registered'")[0]['c']);
        self::assertSame('success', $this->db->select('SELECT outcome FROM login_attempts')[0]['outcome']);
    }

    public function testNoAccountWithoutConsent(): void
    {
        $this->socialLogin($this->profile());

        $response = $this->consent('Иван', agree: false);

        self::assertSame('/auth/social/consent', $response->header('Location'));
        self::assertStringContainsString('согласитесь на обработку персональных данных', $this->follow($response)->body);
        self::assertSame(0, $this->userCount());
        self::assertNull($this->db->select('SELECT 1 AS x FROM user_identities')[0] ?? null);
    }

    public function testConsentNeedsAPendingSignUp(): void
    {
        self::assertSame('/login', $this->get('/auth/social/consent')->header('Location'));
        self::assertSame('/login', $this->consent()->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testConsentStepExpires(): void
    {
        $this->socialLogin($this->profile());
        $this->clock->advance(SocialFlow::TTL + 1);

        self::assertSame('/login', $this->get('/auth/social/consent')->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testKnownProviderAccountSignsInDirectly(): void
    {
        $this->registerViaSocial($this->profile());
        $this->post('/logout');

        $response = $this->socialLogin($this->profile());

        self::assertSame('/app', $response->header('Location'));
        self::assertSame(200, $this->get('/app')->status);
        self::assertSame(1, $this->userCount());
    }

    public function testUnverifiedProviderEmailIsNotStoredOnTheAccount(): void
    {
        $this->socialLogin($this->profile(email: 'claimed@example.com', verified: false));
        $this->consent();

        $user = $this->db->select('SELECT * FROM users')[0];
        self::assertNull($user['email']);
        self::assertNull($user['email_verified_at']);
        // The account is usable even though it has no email to confirm.
        self::assertSame(200, $this->get('/app')->status);
    }

    public function testUnverifiedProviderEmailNeverMatchesAnExistingAccount(): void
    {
        $existing = $this->createUser('ivan@example.com');

        $response = $this->socialLogin($this->profile(email: 'ivan@example.com', verified: false));

        self::assertSame('/auth/social/consent', $response->header('Location'), 'treated as a stranger, not as the account owner');
        $this->consent();
        self::assertSame(2, $this->userCount());
        self::assertSame([], $this->linkedProviders($existing->id));
    }

    public function testVerifiedEmailOfAnExistingAccountAsksForThePasswordInsteadOfSigningIn(): void
    {
        $existing = $this->createUser('ivan@example.com');

        $response = $this->socialLogin($this->profile(email: 'IVAN@example.com'));

        self::assertSame('/login', $response->header('Location'));
        $page = $this->follow($response)->body;
        self::assertStringContainsString('Аккаунт с почтой IVAN@example.com уже есть', $page);
        self::assertSame(302, $this->get('/app')->status, 'not signed in');
        self::assertSame([], $this->linkedProviders($existing->id), 'not linked yet');
        self::assertSame(1, $this->userCount());

        // Proving the password finishes the job.
        $signedIn = $this->signIn('ivan@example.com');
        self::assertSame('/app', $signedIn->header('Location'));
        self::assertSame(['fake'], $this->linkedProviders($existing->id));
        self::assertStringContainsString('привязан', $this->get('/app')->body);

        $this->post('/logout');
        self::assertSame('/app', $this->socialLogin($this->profile(email: 'ivan@example.com'))->header('Location'));
    }

    public function testHeldBackProfileIsNotLinkedToAnotherAccount(): void
    {
        $this->createUser('ivan@example.com');
        $other = $this->createUser('other@example.com');
        $this->socialLogin($this->profile(email: 'ivan@example.com'));

        $this->signIn('other@example.com');

        self::assertSame([], $this->linkedProviders($other->id));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities')[0]['c']);
    }

    public function testHeldBackProfileExpires(): void
    {
        $existing = $this->createUser('ivan@example.com');
        $this->socialLogin($this->profile(email: 'ivan@example.com'));
        $this->clock->advance(SocialFlow::TTL + 1);

        $this->signIn('ivan@example.com');

        self::assertSame([], $this->linkedProviders($existing->id));
    }

    public function testSecondFactorIsAskedAfterSocialSignIn(): void
    {
        $this->registerViaSocial($this->profile());
        $user = $this->db->select('SELECT id FROM users')[0];
        $users = $this->app->container()->get(\App\Domain\User\UserRepository::class);
        $account = $users->find((int) $user['id']);
        self::assertNotNull($account);
        $this->enableTwoFactor($account);
        $this->post('/logout');

        $response = $this->socialLogin($this->profile());

        self::assertSame('/login/2fa', $response->header('Location'));
        self::assertSame(302, $this->get('/app')->status, 'not signed in before the code');
        $second = $this->post('/login/2fa', ['code' => $this->totpCode($account->id)]);
        self::assertSame('/app', $second->header('Location'));
        self::assertSame(200, $this->get('/app')->status);
    }

    public function testBlockedAccountCannotSignInSocially(): void
    {
        $this->registerViaSocial($this->profile());
        $this->post('/logout');
        $this->db->execute("UPDATE users SET status = 'blocked'");

        $response = $this->socialLogin($this->profile());

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('заблокирован', $this->follow($response)->body);
        self::assertSame(302, $this->get('/app')->status);
        self::assertSame('blocked', $this->db->select('SELECT outcome FROM login_attempts ORDER BY id DESC LIMIT 1')[0]['outcome']);
    }

    public function testAccountWithoutEmailPassesTheVerifiedEmailGate(): void
    {
        $this->registerViaSocial($this->profile(email: null));

        self::assertSame(200, $this->get('/app')->status);
        self::assertSame(200, $this->get('/account/security')->status);
    }

    // --- state, PKCE, forged callbacks --------------------------------------------------------------

    public function testCallbackWithoutAStartedFlowIsRefused(): void
    {
        $flow = ['state' => 'whatever', 'challenge' => 'x', 'nonce' => 'y'];

        $response = $this->answer($flow, $this->profile());

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('устарела или уже использована', $this->follow($response)->body);
        self::assertSame(0, $this->userCount());
    }

    public function testWrongStateIsRefused(): void
    {
        $flow = $this->startLogin();

        $response = $this->answer($flow, $this->profile(), state: 'forged-state');

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
        // The legitimate state was consumed by the failed attempt: a stolen state cannot be retried.
        self::assertSame('/login', $this->answer($flow, $this->profile())->header('Location'));
    }

    public function testMissingStateIsRefused(): void
    {
        $flow = $this->startLogin();
        $code = \App\Integrations\OAuth\FakeProvider::makeCode($this->profile(), $flow['challenge'], $flow['nonce']);

        $response = $this->get('/auth/fake/callback?' . http_build_query(['code' => $code]));

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('устарела', $this->follow($response)->body);
    }

    public function testStateWorksOnlyOnce(): void
    {
        $flow = $this->startLogin();
        self::assertSame('/auth/social/consent', $this->answer($flow, $this->profile())->header('Location'));

        $replay = $this->answer($flow, $this->profile());

        self::assertSame('/login', $replay->header('Location'));
        self::assertStringContainsString('устарела или уже использована', $this->follow($replay)->body);
    }

    public function testStateExpires(): void
    {
        $flow = $this->startLogin();
        $this->clock->advance(SocialFlow::TTL + 1);

        self::assertSame('/login', $this->answer($flow, $this->profile())->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testStateIsBoundToTheBrowserSession(): void
    {
        $flow = $this->startLogin();
        $this->useBrowser();

        $response = $this->answer($flow, $this->profile());

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testSwappedPkceChallengeIsRefused(): void
    {
        $flow = $this->startLogin();
        // A code that was issued for somebody else's verifier.
        $response = $this->answer($flow, $this->profile(), challenge: $this->challengeFor('another-verifier'));

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('Не удалось войти', $this->follow($response)->body);
        self::assertSame(0, $this->userCount());
    }

    public function testDeniedConsentAtTheProviderIsHandledGently(): void
    {
        $flow = $this->startLogin();

        $response = $this->get('/auth/fake/callback?' . http_build_query(['error' => 'access_denied', 'state' => $flow['state']]));

        self::assertSame('/login', $response->header('Location'));
        self::assertStringContainsString('не завершён', $this->follow($response)->body);
    }

    public function testGarbageCodeIsRefused(): void
    {
        $flow = $this->startLogin();

        $response = $this->get('/auth/fake/callback?' . http_build_query(['code' => 'not-a-code', 'state' => $flow['state']]));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testWrongNonceIsRefused(): void
    {
        $flow = $this->startLogin();
        $code = \App\Integrations\OAuth\FakeProvider::makeCode($this->profile(), $flow['challenge'], 'other-nonce');

        $response = $this->get('/auth/fake/callback?' . http_build_query(['code' => $code, 'state' => $flow['state']]));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testProvidersThatAreNotEnabledDoNotExist(): void
    {
        foreach (['/auth/vkid/redirect', '/auth/google/redirect', '/auth/telegram/redirect', '/auth/vkid/callback', '/auth/nonsense/callback'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->get('/auth/fake/redirect/extra')->status);
    }

    public function testSignedInVisitorsAreNotSentToTheProvider(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame('/app', $this->get('/auth/fake/redirect')->header('Location'));
    }

    // --- redirects -----------------------------------------------------------------------------------

    public function testRelativeNextIsHonouredAfterSignIn(): void
    {
        $this->registerViaSocial($this->profile());
        $this->post('/logout');

        $flow = $this->startLogin('?next=' . rawurlencode('/account/security'));
        $response = $this->answer($flow, $this->profile());

        self::assertSame('/account/security', $response->header('Location'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function evilTargets(): array
    {
        return [
            'absolute url' => ['https://evil.example/phish'],
            'protocol relative' => ['//evil.example/phish'],
            'backslash trick' => ['/\\evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'control character' => ["/app\r\nSet-Cookie: x=1"],
        ];
    }

    #[DataProvider('evilTargets')]
    public function testNextCannotLeaveTheSite(string $next): void
    {
        $this->registerViaSocial($this->profile());
        $this->post('/logout');

        $flow = $this->startLogin('?next=' . rawurlencode($next));
        $response = $this->answer($flow, $this->profile());

        self::assertSame('/app', $response->header('Location'));
    }

    #[DataProvider('evilTargets')]
    public function testNextCannotLeaveTheSiteAfterSignUp(string $next): void
    {
        $flow = $this->startLogin('?next=' . rawurlencode($next));
        $this->answer($flow, $this->profile());

        self::assertSame('/app', $this->consent()->header('Location'));
    }

    // --- network failures ------------------------------------------------------------------------------

    public function testErrorsFromTheProviderDoNotLeakDetails(): void
    {
        $flow = $this->startLogin();
        $code = \App\Integrations\OAuth\FakeProvider::makeCode($this->profile(), 'mismatch', $flow['nonce']);

        $page = $this->follow($this->get('/auth/fake/callback?' . http_build_query(['code' => $code, 'state' => $flow['state']])))->body;

        self::assertStringNotContainsString('PKCE', $page);
        self::assertStringNotContainsString('verifier', $page);
    }
}
