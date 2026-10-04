<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Integrations\OAuth\GoogleProvider;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Mail\Mailer;
use App\Support\Clock;
use App\Tests\Support\JwtFactory;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\SocialTestCase;
use App\Tests\Support\TestEnv;

/**
 * End-to-end through the real provider adapters with recorded replies: the browser is redirected to the
 * provider's host, the callback is checked, and the (mocked) token and profile endpoints are called with
 * the PKCE verifier we generated.
 */
final class SocialProviderFlowTest extends SocialTestCase
{
    private MockHttpClient $http;

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env): void
    {
        $this->app = TestEnv::app($env);
        $this->cookies = [];
        $this->http = new MockHttpClient();
        $container = $this->app->container();
        $container->instance(Clock::class, $this->clock);
        $container->instance(Mailer::class, $this->mailer);
        $container->instance(HttpClientInterface::class, $this->http);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/oauth/' . $name);
    }

    public function testVkIdSignUp(): void
    {
        $this->boot(['VKID_CLIENT_ID' => '777', 'VKID_CLIENT_SECRET' => '']);
        self::assertStringContainsString('href="/auth/vkid/redirect"', $this->get('/login')->body);

        $redirect = $this->get('/auth/vkid/redirect');
        $location = (string) $redirect->header('Location');
        self::assertStringStartsWith('https://id.vk.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        self::assertSame('http://localhost/auth/vkid/callback', $q['redirect_uri']);

        $this->http->expect('POST', 'https://id.vk.com/oauth2/auth', 200, $this->fixture('vkid_token.json'));
        $this->http->expect('POST', 'https://id.vk.com/oauth2/user_info', 200, $this->fixture('vkid_user_info.json'));
        $callback = $this->get('/auth/vkid/callback?' . http_build_query(['code' => 'c-1', 'state' => $q['state'], 'device_id' => 'dev-9']));

        self::assertSame('/auth/social/consent', $callback->header('Location'));
        $this->http->assertAllConsumed();
        $form = $this->http->requests[0]['options']['form_params'];
        self::assertSame('dev-9', $form['device_id']);
        self::assertSame($q['code_challenge'], $this->challengeFor($form['code_verifier']), 'the verifier matches the challenge sent to VK');
        // VK does not vouch for the email: the account is created without one.
        $this->consent();
        $user = $this->db->select('SELECT * FROM users')[0];
        self::assertNull($user['email']);
        self::assertSame(['vkid'], $this->linkedProviders((int) $user['id']));
    }

    public function testProviderOutageShowsAFriendlyMessage(): void
    {
        $this->boot(['VKID_CLIENT_ID' => '777']);
        parse_str((string) parse_url((string) $this->get('/auth/vkid/redirect')->header('Location'), PHP_URL_QUERY), $q);
        $this->http->expect('POST', 'https://id.vk.com/oauth2/auth', 503, 'upstream down');

        $response = $this->get('/auth/vkid/callback?' . http_build_query(['code' => 'c', 'state' => $q['state'], 'device_id' => 'd']));

        self::assertSame('/login', $response->header('Location'));
        $page = $this->follow($response)->body;
        self::assertStringContainsString('Не удалось войти через VK ID', $page);
        self::assertStringNotContainsString('upstream', $page);
        self::assertSame(0, $this->userCount());
    }

    public function testGoogleSignInChecksTheNonceInTheIdToken(): void
    {
        $this->boot(['GOOGLE_CLIENT_ID' => 'g-client', 'GOOGLE_CLIENT_SECRET' => 'g-secret']);
        $jwt = new JwtFactory();
        $location = (string) $this->get('/auth/google/redirect')->header('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $now = $this->clock->now()->getTimestamp();
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'g-client', 'sub' => 'g-sub-1', 'email' => 'ivan@gmail.com', 'email_verified' => true, 'name' => 'Ivan', 'nonce' => $q['nonce'], 'iat' => $now, 'exp' => $now + 600];
        $this->http->expect('POST', 'https://oauth2.googleapis.com/token', 200, str_replace('__ID_TOKEN__', $jwt->token($claims), $this->fixture('google_token.json.tpl')));
        $this->http->expect('GET', GoogleProvider::JWKS_URL, 200, $jwt->jwks());

        $callback = $this->get('/auth/google/callback?' . http_build_query(['code' => 'c', 'state' => $q['state']]));

        self::assertSame('/auth/social/consent', $callback->header('Location'));
        $this->consent('Ivan');
        $user = $this->db->select('SELECT * FROM users')[0];
        self::assertSame('ivan@gmail.com', $user['email']);
        self::assertNotNull($user['email_verified_at']);
    }

    public function testGoogleTokenWithAForeignNonceIsRefused(): void
    {
        $this->boot(['GOOGLE_CLIENT_ID' => 'g-client', 'GOOGLE_CLIENT_SECRET' => 'g-secret']);
        $jwt = new JwtFactory();
        parse_str((string) parse_url((string) $this->get('/auth/google/redirect')->header('Location'), PHP_URL_QUERY), $q);
        $now = $this->clock->now()->getTimestamp();
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'g-client', 'sub' => 'g-sub-1', 'email' => 'x@gmail.com', 'email_verified' => true, 'nonce' => 'replayed-from-elsewhere', 'iat' => $now, 'exp' => $now + 600];
        $this->http->expect('POST', 'https://oauth2.googleapis.com/token', 200, str_replace('__ID_TOKEN__', $jwt->token($claims), $this->fixture('google_token.json.tpl')));
        $this->http->expect('GET', GoogleProvider::JWKS_URL, 200, $jwt->jwks());

        $response = $this->get('/auth/google/callback?' . http_build_query(['code' => 'c', 'state' => $q['state']]));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $this->userCount());
    }

    public function testYandexVerifiedEmailMatchesAnExistingAccountOnlyAfterPassword(): void
    {
        $this->boot(['YANDEX_CLIENT_ID' => 'y', 'YANDEX_CLIENT_SECRET' => 's']);
        $existing = $this->createUser('ivan.petrov@yandex.ru');
        parse_str((string) parse_url((string) $this->get('/auth/yandex/redirect')->header('Location'), PHP_URL_QUERY), $q);
        $this->http->expect('POST', 'https://oauth.yandex.ru/token', 200, $this->fixture('yandex_token.json'));
        $this->http->expect('GET', 'https://login.yandex.ru/info?format=json', 200, $this->fixture('yandex_info.json'));

        $response = $this->get('/auth/yandex/callback?' . http_build_query(['code' => 'c', 'state' => $q['state']]));

        self::assertSame('/login', $response->header('Location'));
        self::assertSame('/login', $this->get('/app')->header('Location'));
        self::assertSame([], $this->linkedProviders($existing->id));
        $this->signIn('ivan.petrov@yandex.ru');
        self::assertSame(['yandex'], $this->linkedProviders($existing->id));
    }

    public function testFakeProviderPagesWorkEndToEndInTheBrowser(): void
    {
        $flow = $this->startLogin();
        $page = $this->get('/dev/oauth/fake?' . http_build_query($flow));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="state" value="' . $flow['state'] . '"', $page->body);

        $approve = $this->get('/dev/oauth/fake/approve?' . http_build_query($flow + ['id' => 'dev-1', 'name' => 'Dev', 'email' => 'dev@example.com', 'email_verified' => '1']));
        self::assertStringStartsWith('/auth/fake/callback?', (string) $approve->header('Location'));

        $callback = $this->get((string) $approve->header('Location'));
        self::assertSame('/auth/social/consent', $callback->header('Location'));
        self::assertStringContainsString('dev@example.com', $this->get('/auth/social/consent')->body);
    }

    public function testFakeProviderPagesAreGoneWhenSwitchedOff(): void
    {
        $this->boot(['DEV_OAUTH_FAKE' => '0']);

        foreach (['/dev/oauth/fake?state=a&challenge=b&nonce=c', '/dev/oauth/fake/approve?state=a&challenge=b&nonce=c&id=1', '/auth/fake/redirect', '/auth/fake/callback?code=x&state=y'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertStringNotContainsString('/auth/fake/redirect', $this->get('/login')->body);
    }

    public function testFakeApproveNeedsTheFlowParameters(): void
    {
        self::assertSame(404, $this->get('/dev/oauth/fake')->status);
        self::assertSame(404, $this->get('/dev/oauth/fake/approve?id=1')->status);
    }
}
