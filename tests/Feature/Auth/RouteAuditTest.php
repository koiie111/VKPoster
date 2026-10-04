<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Kernel\Http\Route;
use App\Kernel\Http\Router;
use App\Tests\Support\AuthTestCase;

/**
 * Audits the whole route table: every state-changing route demands a CSRF token, and every route that
 * belongs to a signed-in user sends guests to the login form.
 */
final class RouteAuditTest extends AuthTestCase
{
    /**
     * @return list<Route>
     */
    private function applicationRoutes(): array
    {
        $routes = $this->app->container()->get(Router::class)->routes();

        return array_values(array_filter($routes, static fn (Route $r): bool => !str_starts_with($r->pattern, '/_t/') && !str_starts_with($r->pattern, '/api/_t/')));
    }

    private function concretePath(Route $route): string
    {
        $path = preg_replace_callback(
            '/\{(\w+)(?::(?:[^{}]|\{[^{}]*\})+)?\}/',
            static fn (array $m): string => match ($m[1]) {
                'token' => str_repeat('a', 43),
                'id' => str_repeat('0', 26),
                'provider' => 'fake',
                default => '1',
            },
            $route->pattern,
        );

        return $path ?? $route->pattern;
    }

    public function testEveryStateChangingRouteRequiresCsrf(): void
    {
        $checked = 0;
        foreach ($this->applicationRoutes() as $route) {
            if (array_diff($route->methods, ['GET', 'HEAD']) === [] || !$route->requiresCsrf()) {
                continue;
            }
            $path = $this->concretePath($route);
            // No `/dev/login-as`-style GET routes here: only methods that change state.
            $response = $this->request($route->methods[0], $path, ['x' => 'y']);
            self::assertSame(419, $response->status, $route->methods[0] . ' ' . $path . ' must reject a missing CSRF token');
            ++$checked;
        }

        self::assertGreaterThan(15, $checked);
    }

    public function testAccountRoutesRedirectGuestsToLogin(): void
    {
        $checked = 0;
        foreach ($this->applicationRoutes() as $route) {
            $isAccountArea = str_starts_with($route->pattern, '/account/') || in_array($route->pattern, ['/app', '/logout', '/logout/all', '/email/verification', '/email/verification/resend'], true);
            if (!$isAccountArea) {
                continue;
            }
            $path = $this->concretePath($route);
            $response = in_array('GET', $route->methods, true) ? $this->get($path) : $this->post($path);
            self::assertSame(302, $response->status, $path);
            self::assertSame('/login', $response->header('Location'), $path);
            ++$checked;
        }

        self::assertGreaterThan(10, $checked);
    }

    public function testPublicAuthPagesRenderForGuests(): void
    {
        foreach (['/login', '/register', '/password/forgot'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status, $path);
            self::assertStringContainsString('name="_token"', $response->body, $path . ' has a CSRF field');
            self::assertStringContainsString("script-src 'self' 'nonce-", (string) $response->header('Content-Security-Policy'));
        }
    }

    public function testAuthPagesAreNeverCached(): void
    {
        self::assertSame('no-store', $this->get('/login')->header('Cache-Control'));
    }

    public function testLandingMenuLeadsToSignInAndSignUp(): void
    {
        $page = $this->get('/')->body;

        self::assertStringContainsString('href="/login">Войти</a>', $page);
        self::assertStringContainsString('href="/register">Начать</a>', $page);
    }
}
