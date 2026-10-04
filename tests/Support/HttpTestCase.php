<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RateLimit;
use App\Kernel\Application;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Base class for feature tests: builds the real application in-process, registers the test-only
 * routes, and keeps a cookie jar so a "browser" can span several requests.
 */
abstract class HttpTestCase extends TestCase
{
    protected Application $app;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->app = TestEnv::app();
        $this->cookies = [];
        $router = $this->app->container()->get(Router::class);
        $router->post('/_t/echo', [TestController::class, 'echo']);
        $router->post('/_t/hook', [TestController::class, 'echo'])->withoutCsrf();
        $router->get('/_t/boom', [TestController::class, 'boom']);
        $router->post('/_t/login', [TestController::class, 'login']);
        $router->get('/_t/me', [TestController::class, 'whoami'])->middleware(Authenticate::class);
        $router->get('/_t/items/{id:[0-9]+}', [TestController::class, 'item']);
        $router->post('/_t/flash', [TestController::class, 'flash']);
        $router->get('/_t/flash/read', [TestController::class, 'readFlash']);
        $router->get('/api/_t/data', [TestController::class, 'data']);
        $router->get('/api/_t/boom', [TestController::class, 'boom']);
        $router->get('/_t/limited', [TestController::class, 'data'])
            ->middleware([RateLimit::class, ['bucket' => 'test-' . bin2hex(random_bytes(4)), 'max' => 2, 'seconds' => 60]]);
        $router->indexNames();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $body = [], array $headers = []): Response
    {
        $headers += ['Host' => 'localhost'];
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                $query[(string) $key] = $value;
            }
        }
        $request = Request::create(
            $method,
            $path,
            query: $query,
            body: $body,
            headers: $headers,
            cookies: $this->cookies,
            server: ['REMOTE_ADDR' => '203.0.113.10'],
        );
        $response = $this->app->handle($request);
        foreach ($response->cookies as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if (str_contains($cookie, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * Fetch a page to start a session and return its CSRF token (from the meta tag).
     */
    protected function csrfToken(): string
    {
        $html = $this->get('/')->body;
        if (preg_match('/name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) !== 1) {
            self::fail('csrf meta tag missing');
        }

        return $m[1];
    }

    protected function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
