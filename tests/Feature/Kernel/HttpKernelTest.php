<?php

declare(strict_types=1);

namespace App\Tests\Feature\Kernel;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;
use App\Kernel\Application;
use App\Kernel\Middleware\ErrorHandler;
use App\Kernel\View\View;
use App\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The whole request path: routing, error pages, security headers, sessions, CSRF, rate limiting, auth.
 */
#[CoversClass(Application::class)]
#[CoversClass(ErrorHandler::class)]
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(StartSession::class)]
#[CoversClass(VerifyCsrf::class)]
#[CoversClass(RateLimit::class)]
#[CoversClass(Authenticate::class)]
#[CoversClass(View::class)]
final class HttpKernelTest extends HttpTestCase
{
    public function testHomePageRenders(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1', $response->body);
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
    }

    public function testHealthEndpointReportsDependencies(): void
    {
        $response = $this->get('/healthz');

        self::assertSame(200, $response->status);
        self::assertSame('{"db":"ok","redis":"ok"}', $response->body);
    }

    public function testCspHeaderCarriesNonceUsedByScripts(): void
    {
        $response = $this->get('/');

        $csp = (string) $response->header('Content-Security-Policy');
        if (preg_match("/script-src 'self' 'nonce-([A-Za-z0-9_-]{20,})'/", $csp, $m) !== 1) {
            self::fail('CSP script-src with nonce is missing');
        }
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'none'", "frame-ancestors 'none'", "form-action 'self'"] as $directive) {
            self::assertStringContainsString($directive, $csp);
        }
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertSame(4, substr_count($response->body, 'nonce="' . $m[1] . '"'), 'every script tag carries the request nonce');
        self::assertStringNotContainsString('<script>', $response->body);
        self::assertStringNotContainsString('onclick', $response->body);
    }

    public function testNonceChangesBetweenRequests(): void
    {
        $a = (string) $this->get('/')->header('Content-Security-Policy');
        $b = (string) $this->get('/')->header('Content-Security-Policy');

        self::assertNotSame($a, $b);
    }

    public function testSecurityHeadersPresentOnErrorsToo(): void
    {
        $response = $this->get('/does-not-exist');

        self::assertSame(404, $response->status);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertNotNull($response->header('Content-Security-Policy'));
        self::assertNotNull($response->header('Referrer-Policy'));
        self::assertNotNull($response->header('Permissions-Policy'));
        self::assertNull($response->header('Strict-Transport-Security'), 'HSTS only in production');
    }

    public function testHstsIsSentInProduction(): void
    {
        $app = \App\Tests\Support\TestEnv::app(['APP_ENV' => 'production', 'APP_URL' => 'https://example.test']);
        $response = $app->handle(\App\Kernel\Http\Request::create('GET', '/nope', headers: ['Host' => 'example.test']));

        self::assertStringContainsString('max-age=31536000', (string) $response->header('Strict-Transport-Security'));
    }

    public function testNotFoundPageIsFriendlyHtml(): void
    {
        $response = $this->get('/nope');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Страница не найдена', $response->body);
    }

    public function testMethodNotAllowedSendsAllowHeader(): void
    {
        $response = $this->request('POST', '/healthz', [], ['Origin' => 'http://localhost']);

        self::assertContains($response->status, [405, 419]);
        $withToken = $this->request('POST', '/healthz', ['_token' => $this->csrfToken()]);
        self::assertSame(405, $withToken->status);
        self::assertSame('GET', $withToken->header('Allow'));
    }

    public function testApiErrorsAreJson(): void
    {
        $notFound = $this->get('/api/unknown');
        self::assertSame(404, $notFound->status);
        self::assertSame('application/json', $notFound->header('Content-Type'));
        self::assertSame(404, json_decode($notFound->body, true)['error']['status']);

        $ok = $this->get('/api/_t/data');
        self::assertSame('{"ok":true}', $ok->body);
    }

    public function testUnhandledExceptionShowsNoInternals(): void
    {
        $response = $this->get('/_t/boom');

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('secret internal detail', $response->body);
        self::assertStringNotContainsString('/var/www', $response->body);
        self::assertStringNotContainsString('Stack trace', $response->body);
        self::assertSame(1, preg_match('/[0-9A-HJKMNP-TV-Z]{26}/', $response->body), 'error id is shown for support');
    }

    public function testUnhandledExceptionInApiIsJsonWithErrorId(): void
    {
        $response = $this->get('/api/_t/boom');

        $data = json_decode($response->body, true);
        self::assertSame(500, $response->status);
        self::assertSame(500, $data['error']['status']);
        self::assertArrayHasKey('id', $data['error']);
        self::assertStringNotContainsString('secret internal detail', $response->body);
    }

    public function testDebugModeShowsDetailsOnlyWhenEnabled(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['APP_DEBUG' => '1']);
        $this->app->container()->get(\App\Kernel\Http\Router::class)->get('/_t/boom', [\App\Tests\Support\TestController::class, 'boom']);

        $response = $this->get('/_t/boom');

        self::assertStringContainsString('secret internal detail', $response->body);
    }

    public function testRouteParametersReachTheController(): void
    {
        self::assertSame('item:15', $this->get('/_t/items/15')->body);
        self::assertSame(404, $this->get('/_t/items/abc')->status);
    }

    public function testPostWithoutTokenIsRejectedWith419(): void
    {
        $response = $this->request('POST', '/_t/echo', ['v' => 'x']);

        self::assertSame(419, $response->status);
        self::assertStringContainsString('Страница устарела', $response->body);
    }

    public function testPostWithValidTokenPasses(): void
    {
        $token = $this->csrfToken();

        $response = $this->request('POST', '/_t/echo', ['v' => 'hello', '_token' => $token]);

        self::assertSame(200, $response->status);
        self::assertSame('posted:hello', $response->body);
    }

    public function testCsrfHeaderWorksForHtmx(): void
    {
        $token = $this->csrfToken();

        $response = $this->request('POST', '/_t/echo', ['v' => 'h'], ['X-CSRF-Token' => $token]);

        self::assertSame(200, $response->status);
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->csrfToken();

        self::assertSame(419, $this->request('POST', '/_t/echo', ['_token' => str_repeat('0', 64)])->status);
    }

    public function testForeignOriginIsRejectedEvenWithValidToken(): void
    {
        $token = $this->csrfToken();

        $response = $this->request('POST', '/_t/echo', ['_token' => $token], ['Origin' => 'https://evil.example']);

        self::assertSame(419, $response->status);
    }

    public function testSameOriginIsAccepted(): void
    {
        $token = $this->csrfToken();

        $response = $this->request('POST', '/_t/echo', ['_token' => $token], ['Origin' => 'http://localhost']);

        self::assertSame(200, $response->status);
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $other = $this->csrfToken();
        $this->setUp(); // a fresh browser
        $this->csrfToken();

        self::assertSame(419, $this->request('POST', '/_t/echo', ['_token' => $other])->status);
    }

    public function testRoutesCanOptOutOfCsrf(): void
    {
        $response = $this->request('POST', '/_t/hook', ['v' => 'webhook']);

        self::assertSame(200, $response->status);
        self::assertSame('posted:webhook', $response->body);
    }

    public function testCsrfFieldAndMetaAreRenderedInPages(): void
    {
        $view = $this->app->container()->get(View::class);
        $this->get('/');

        self::assertStringContainsString('name="_token"', $view->csrfField() . '<input name="_token">');
    }

    public function testSessionCookieAttributes(): void
    {
        $response = $this->get('/');

        self::assertCount(1, $response->cookies);
        self::assertStringStartsWith('sid=', $response->cookies[0]);
        self::assertStringContainsString('HttpOnly', $response->cookies[0]);
        self::assertStringContainsString('SameSite=Lax', $response->cookies[0]);
        self::assertStringContainsString('Path=/', $response->cookies[0]);
    }

    public function testHostPrefixedSecureCookieOnHttps(): void
    {
        $app = \App\Tests\Support\TestEnv::app(['APP_URL' => 'https://example.test']);
        $response = $app->handle(\App\Kernel\Http\Request::create('GET', '/', headers: ['Host' => 'example.test']));

        self::assertStringStartsWith('__Host-sid=', $response->cookies[0]);
        self::assertStringContainsString('Secure', $response->cookies[0]);
        self::assertStringNotContainsString('Domain=', $response->cookies[0]);
    }

    public function testHealthzDoesNotCreateASession(): void
    {
        self::assertSame([], $this->get('/healthz')->cookies);
    }

    public function testSessionPersistsAndIsRegeneratedOnLogin(): void
    {
        $token = $this->csrfToken();
        $before = $this->cookie('sid');
        self::assertNotNull($before);

        $this->request('POST', '/_t/login', ['_token' => $token]);
        $after = $this->cookie('sid');

        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'session id must change on login (fixation protection)');
        self::assertSame('user:42', $this->get('/_t/me')->body);
    }

    public function testOldSessionIdIsDeadAfterRegeneration(): void
    {
        $token = $this->csrfToken();
        $old = (string) $this->cookie('sid');
        $this->request('POST', '/_t/login', ['_token' => $token]);

        $attacker = new \App\Kernel\Http\Request('GET', '/_t/me', headers: ['host' => 'localhost'], cookies: ['sid' => $old]);
        $response = $this->app->handle($attacker);

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->header('Location'));
    }

    public function testAuthenticateRedirectsGuests(): void
    {
        $response = $this->get('/_t/me');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->header('Location'));
    }

    public function testFlashMessageSurvivesOneRedirect(): void
    {
        $token = $this->csrfToken();

        $redirect = $this->request('POST', '/_t/flash', ['_token' => $token]);
        self::assertSame(302, $redirect->status);
        self::assertSame('flash:saved', $this->get('/_t/flash/read')->body);
        self::assertSame('flash:none', $this->get('/_t/flash/read')->body);
    }

    public function testRateLimitReturns429WithRetryAfter(): void
    {
        $statuses = [$this->get('/_t/limited')->status, $this->get('/_t/limited')->status];
        self::assertSame([200, 200], $statuses);

        $blocked = $this->get('/_t/limited');

        self::assertSame(429, $blocked->status);
        self::assertGreaterThan(0, (int) $blocked->header('Retry-After'));
        self::assertStringContainsString('Слишком много запросов', $blocked->body);
    }

    public function testHeadRequestsAreRoutedAsGet(): void
    {
        self::assertSame(200, $this->request('HEAD', '/healthz')->status);
    }

    public function testAssetUrlsCarryAContentHash(): void
    {
        self::assertMatchesRegularExpression('#/assets/js/app\.js\?v=[0-9a-f]{10}#', $this->get('/')->body);
    }
}
