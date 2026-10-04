<?php

declare(strict_types=1);

namespace App\Tests\Unit\OAuth;

use App\Integrations\OAuth\GoogleProvider;
use App\Integrations\OAuth\JwtVerifier;
use App\Integrations\OAuth\OAuthException;
use App\Integrations\OAuth\Pkce;
use App\Integrations\OAuth\VkIdProvider;
use App\Integrations\OAuth\YandexProvider;
use App\Tests\Support\FakeClock;
use App\Tests\Support\JwtFactory;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Provider adapters against recorded replies (tests/Fixtures/oauth). Nothing here touches the network.
 */
#[CoversClass(VkIdProvider::class)]
#[CoversClass(YandexProvider::class)]
#[CoversClass(GoogleProvider::class)]
#[CoversClass(Pkce::class)]
final class ProvidersTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/oauth/' . $name);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $key => $value) {
            $query[(string) $key] = is_string($value) ? $value : '';
        }

        return $query;
    }

    // --- PKCE ---------------------------------------------------------------------------------------

    public function testPkceChallengeFollowsRfc7636(): void
    {
        // The example from RFC 7636 appendix B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
        $pair = Pkce::generate();
        self::assertSame(Pkce::challengeFor($pair->verifier), $pair->challenge);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $pair->verifier);
        self::assertNotSame($pair->verifier, Pkce::generate()->verifier);
    }

    // --- VK ID --------------------------------------------------------------------------------------

    public function testVkAuthorizationUrlCarriesStatePkceAndProfileScopesOnly(): void
    {
        $provider = new VkIdProvider($this->http, '777', 'secret');
        $pkce = Pkce::generate();

        $url = $provider->authorizationUrl('https://app.test/auth/vkid/callback', 'st', $pkce, 'nonce');

        self::assertStringStartsWith('https://id.vk.com/authorize?', $url);
        $q = $this->query($url);
        self::assertSame('code', $q['response_type']);
        self::assertSame('777', $q['client_id']);
        self::assertSame('st', $q['state']);
        self::assertSame($pkce->challenge, $q['code_challenge']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame('https://app.test/auth/vkid/callback', $q['redirect_uri']);
        self::assertSame('vkid.personal_info email', $q['scope'], 'no wall or photo rights at sign-in');
        self::assertStringNotContainsString('secret', $url);
    }

    public function testVkExchangeSendsVerifierAndDeviceId(): void
    {
        $provider = new VkIdProvider($this->http, '777', 'secret');
        $this->http->expect('POST', 'https://id.vk.com/oauth2/auth', 200, $this->fixture('vkid_token.json'));

        $tokens = $provider->exchangeCode('the-code', 'the-verifier', 'https://app.test/cb', ['device_id' => 'dev-1', 'state' => 'st']);

        self::assertSame('vk-access-token', $tokens->accessToken);
        $form = $this->http->requests[0]['options']['form_params'];
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('the-code', $form['code']);
        self::assertSame('the-verifier', $form['code_verifier']);
        self::assertSame('dev-1', $form['device_id']);
        self::assertSame('777', $form['client_id']);
    }

    public function testVkNeedsADeviceId(): void
    {
        $this->expectException(OAuthException::class);
        (new VkIdProvider($this->http, '777', ''))->exchangeCode('c', 'v', 'r', ['state' => 's']);
    }

    public function testVkProfileDoesNotTrustTheEmail(): void
    {
        $provider = new VkIdProvider($this->http, '777', '');
        $this->http->expect('POST', 'https://id.vk.com/oauth2/user_info', 200, $this->fixture('vkid_user_info.json'));

        $profile = $provider->fetchProfile(new \App\Integrations\OAuth\TokenSet('vk-access-token'), 'n');

        self::assertSame('vkid', $profile->provider);
        self::assertSame('1234567890', $profile->id);
        self::assertSame('Иван Петров', $profile->name);
        self::assertSame('ivan@example.com', $profile->email);
        self::assertFalse($profile->emailVerified);
        self::assertNull($profile->trustedEmail());
        self::assertSame('vk-access-token', $this->http->requests[0]['options']['form_params']['access_token']);
    }

    public function testVkErrorRepliesBecomeExceptions(): void
    {
        $provider = new VkIdProvider($this->http, '777', '');
        $this->http->expect('POST', 'https://id.vk.com/oauth2/auth', 400, '{"error":"invalid_grant"}');

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('invalid_grant');
        $provider->exchangeCode('c', 'v', 'r', ['device_id' => 'd']);
    }

    // --- Yandex -------------------------------------------------------------------------------------

    public function testYandexFlow(): void
    {
        $provider = new YandexProvider($this->http, 'yid', 'ysecret');
        $q = $this->query($provider->authorizationUrl('https://app.test/cb', 'st', Pkce::generate(), 'n'));
        self::assertSame('login:email login:info', $q['scope']);
        self::assertSame('S256', $q['code_challenge_method']);

        $this->http->expect('POST', 'https://oauth.yandex.ru/token', 200, $this->fixture('yandex_token.json'));
        $this->http->expect('GET', 'https://login.yandex.ru/info?format=json', 200, $this->fixture('yandex_info.json'));
        $tokens = $provider->exchangeCode('code', 'verifier', 'https://app.test/cb', []);
        $profile = $provider->fetchProfile($tokens, 'n');

        self::assertSame('1000034426', $profile->id);
        self::assertSame('Иван Петров', $profile->name);
        self::assertSame('Ivan.Petrov@yandex.ru', $profile->email);
        self::assertSame('ivan.petrov@yandex.ru', $profile->trustedEmail());
        self::assertSame('https://avatars.yandex.net/get-yapic/131652443-0/islands-200', $profile->avatar);
        self::assertSame('OAuth ya-access-token', $this->http->requests[1]['options']['headers']['Authorization']);
        self::assertSame('ysecret', $this->http->requests[0]['options']['form_params']['client_secret']);
        self::assertSame('verifier', $this->http->requests[0]['options']['form_params']['code_verifier']);
    }

    public function testYandexWithoutAnEmailHasNoTrustedAddress(): void
    {
        $provider = new YandexProvider($this->http, 'yid', 'ysecret');
        $this->http->expect('GET', 'https://login.yandex.ru/info?format=json', 200, '{"id":"5","login":"nick","is_avatar_empty":true}');

        $profile = $provider->fetchProfile(new \App\Integrations\OAuth\TokenSet('t'), 'n');

        self::assertNull($profile->email);
        self::assertNull($profile->trustedEmail());
        self::assertSame('nick', $profile->name);
        self::assertNull($profile->avatar);
    }

    public function testYandexProfileWithoutAnIdIsRejected(): void
    {
        $provider = new YandexProvider($this->http, 'yid', 'ysecret');
        $this->http->expect('GET', 'https://login.yandex.ru/info?format=json', 200, '{"login":"nick"}');

        $this->expectException(OAuthException::class);
        $provider->fetchProfile(new \App\Integrations\OAuth\TokenSet('t'), 'n');
    }

    // --- Google -------------------------------------------------------------------------------------

    private function google(JwtFactory $jwt, FakeClock $clock): GoogleProvider
    {
        return new GoogleProvider($this->http, new JwtVerifier($this->http, $clock), 'g-client', 'g-secret');
    }

    public function testGoogleFlowVerifiesTheIdToken(): void
    {
        $clock = new FakeClock('2026-10-04 12:00:00');
        $jwt = new JwtFactory();
        $provider = $this->google($jwt, $clock);
        $q = $this->query($provider->authorizationUrl('https://app.test/cb', 'st', Pkce::generate(), 'the-nonce'));
        self::assertSame('openid email profile', $q['scope']);
        self::assertSame('the-nonce', $q['nonce']);

        $now = $clock->now()->getTimestamp();
        $idToken = $jwt->token(['iss' => 'https://accounts.google.com', 'aud' => 'g-client', 'sub' => '10769150350006150715113082367', 'email' => 'Ivan@Gmail.com', 'email_verified' => true, 'name' => 'Ivan P', 'picture' => 'https://lh3.googleusercontent.com/a', 'nonce' => 'the-nonce', 'iat' => $now, 'exp' => $now + 3600]);
        $this->http->expect('POST', 'https://oauth2.googleapis.com/token', 200, str_replace('__ID_TOKEN__', $idToken, $this->fixture('google_token.json.tpl')));
        $this->http->expect('GET', GoogleProvider::JWKS_URL, 200, $jwt->jwks());

        $tokens = $provider->exchangeCode('code', 'verifier', 'https://app.test/cb', []);
        $profile = $provider->fetchProfile($tokens, 'the-nonce');

        self::assertSame('google', $profile->provider);
        self::assertSame('10769150350006150715113082367', $profile->id);
        self::assertSame('ivan@gmail.com', $profile->trustedEmail());
        self::assertSame('Ivan P', $profile->name);
        self::assertSame('g-secret', $this->http->requests[0]['options']['form_params']['client_secret']);
    }

    public function testGoogleEmailWithoutTheVerifiedClaimIsNotTrusted(): void
    {
        $clock = new FakeClock('2026-10-04 12:00:00');
        $jwt = new JwtFactory();
        $now = $clock->now()->getTimestamp();
        $idToken = $jwt->token(['iss' => 'accounts.google.com', 'aud' => 'g-client', 'sub' => '1', 'email' => 'a@b.test', 'email_verified' => false, 'nonce' => 'n', 'iat' => $now, 'exp' => $now + 60]);
        $this->http->expect('GET', GoogleProvider::JWKS_URL, 200, $jwt->jwks());

        $profile = $this->google($jwt, $clock)->fetchProfile(new \App\Integrations\OAuth\TokenSet('', $idToken), 'n');

        self::assertNull($profile->trustedEmail());
        self::assertSame('a@b.test', $profile->email);
    }

    public function testGoogleRejectsATokenForAnotherClientOrNonce(): void
    {
        $clock = new FakeClock('2026-10-04 12:00:00');
        $jwt = new JwtFactory();
        $now = $clock->now()->getTimestamp();
        $base = ['iss' => 'https://accounts.google.com', 'aud' => 'g-client', 'sub' => '1', 'nonce' => 'n', 'iat' => $now, 'exp' => $now + 60];

        foreach ([['aud' => 'other-client'], ['nonce' => 'replayed']] as $override) {
            $this->http->expect('GET', GoogleProvider::JWKS_URL, 200, $jwt->jwks());
            try {
                $this->google($jwt, $clock)->fetchProfile(new \App\Integrations\OAuth\TokenSet('', $jwt->token($override + $base)), 'n');
                self::fail('accepted ' . json_encode($override));
            } catch (OAuthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGoogleWithoutAnIdTokenIsRejected(): void
    {
        $this->http->expect('POST', 'https://oauth2.googleapis.com/token', 200, '{"access_token":"a"}');
        $provider = $this->google(new JwtFactory(), new FakeClock('2026-10-04 12:00:00'));

        $this->expectException(OAuthException::class);
        $provider->exchangeCode('c', 'v', 'r', []);
    }
}
