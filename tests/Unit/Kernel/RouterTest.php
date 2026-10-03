<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Matching, parameters, groups, 404/405, named routes.
 */
#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    private const HANDLER = [stdClass::class, 'x'];

    public function testMatchesStaticAndParameterRoutes(): void
    {
        $router = new Router();
        $router->get('/posts', self::HANDLER);
        $router->get('/posts/{id:[0-9A-Z]{26}}', self::HANDLER);
        $router->get('/u/{slug}', self::HANDLER);

        self::assertSame([], $router->dispatch('GET', '/posts')['params']);
        self::assertSame(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], $router->dispatch('GET', '/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV')['params']);
        self::assertSame(['slug' => 'ann'], $router->dispatch('GET', '/u/ann')['params']);
    }

    public function testRegexConstraintRejectsMismatch(): void
    {
        $router = new Router();
        $router->get('/posts/{id:[0-9A-Z]{26}}', self::HANDLER);

        $this->expectException(HttpException::class);
        $router->dispatch('GET', '/posts/42');
    }

    public function testLiteralDotsAreNotWildcards(): void
    {
        $router = new Router();
        $router->get('/robots.txt', self::HANDLER);

        self::assertNull($router->match('GET', '/robotsXtxt'));
        self::assertNotNull($router->match('GET', '/robots.txt'));
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/a', self::HANDLER);
        $router->post('/a', self::HANDLER);

        try {
            $router->dispatch('GET', '/missing');
            self::fail('expected 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        try {
            $router->dispatch('DELETE', '/a');
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('GET, POST', $e->headers['Allow']);
        }
    }

    public function testHeadFallsBackToGet(): void
    {
        $router = new Router();
        $router->get('/a', self::HANDLER);

        self::assertNotNull($router->match('HEAD', '/a'));
    }

    public function testGroupsAddPrefixAndMiddlewareAndRestoreState(): void
    {
        $router = new Router();
        $router->group('/admin', ['auth'], function (Router $r): void {
            $r->get('/users', self::HANDLER);
            $r->group('/deep', ['extra'], function (Router $r): void {
                $r->get('/x', self::HANDLER);
            });
        });
        $router->get('/plain', self::HANDLER);

        self::assertSame(['auth'], $router->dispatch('GET', '/admin/users')['route']->middleware);
        self::assertSame(['auth', 'extra'], $router->dispatch('GET', '/admin/deep/x')['route']->middleware);
        self::assertSame([], $router->dispatch('GET', '/plain')['route']->middleware);
    }

    public function testNamedRoutesBuildUrls(): void
    {
        $router = new Router();
        $router->get('/posts/{id:[0-9]+}/edit', self::HANDLER)->name('posts.edit');
        $router->get('/', self::HANDLER)->name('home');

        self::assertSame('/', $router->url('home'));
        self::assertSame('/posts/12/edit?tab=a%20b', $router->url('posts.edit', ['id' => 12], ['tab' => 'a b']));
    }

    public function testUrlRejectsMalformedOrMissingParams(): void
    {
        $router = new Router();
        $router->get('/posts/{id:[0-9]+}', self::HANDLER)->name('p');

        try {
            $router->url('p', ['id' => 'abc']);
            self::fail('expected exception');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $router->url('p');
    }

    public function testUnknownRouteName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->url('nope');
    }

    public function testRouteCsrfOptOut(): void
    {
        $router = new Router();
        $route = $router->post('/hook', self::HANDLER)->withoutCsrf();

        self::assertFalse($route->requiresCsrf());
        self::assertTrue($router->post('/form', self::HANDLER)->requiresCsrf());
    }
}
