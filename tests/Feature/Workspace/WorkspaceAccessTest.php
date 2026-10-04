<?php

declare(strict_types=1);

namespace App\Tests\Feature\Workspace;

use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\Role;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\ResolveWorkspace;
use App\Kernel\Http\Route;
use App\Kernel\Http\Router;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The tenant boundary: walks the whole route table. Anything under `/w/{workspaceId}` must answer 404 for
 * a signed-in stranger (IDOR), send guests to the login form, and give each role exactly the access the
 * permission matrix promises.
 */
#[CoversClass(ResolveWorkspace::class)]
#[CoversClass(Authorize::class)]
final class WorkspaceAccessTest extends WorkspaceTestCase
{
    private const DUMMY_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    /**
     * @return list<Route>
     */
    private function workspaceRoutes(): array
    {
        $routes = array_values(array_filter(
            $this->app->container()->get(Router::class)->routes(),
            static fn (Route $r): bool => str_starts_with($r->pattern, '/w/{workspaceId'),
        ));
        self::assertGreaterThan(10, count($routes), 'the route table was expected to hold the workspace routes');

        return $routes;
    }

    private function pathFor(Route $route, string $workspaceId): string
    {
        $path = preg_replace_callback(
            '/\{(\w+)(?::(?:[^{}]|\{[^{}]*\})+)?\}/',
            static fn (array $m): string => $m[1] === 'workspaceId' ? $workspaceId : self::DUMMY_ULID,
            $route->pattern,
        );

        return $path ?? $route->pattern;
    }

    private function send(Route $route, string $path): int
    {
        return in_array('GET', $route->methods, true) ? $this->get($path)->status : $this->post($path, ['x' => 'y'])->status;
    }

    private function requiredPermission(Route $route): ?string
    {
        foreach ($route->middleware as $middleware) {
            if (is_array($middleware) && $middleware[0] === Authorize::class) {
                return (string) $middleware[1]['permission'];
            }
        }

        return null;
    }

    public function testEveryWorkspaceRouteIsHiddenFromStrangers(): void
    {
        [, $mine] = $this->ownerWithWorkspace('a@example.com');
        [$stranger] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $this->actAs($stranger);

        foreach ($this->workspaceRoutes() as $route) {
            $path = $this->pathFor($route, $mine->publicId);
            self::assertSame(404, $this->send($route, $path), $route->methods[0] . ' ' . $path . ' must not reveal that the workspace exists');
        }
    }

    public function testAnUnknownWorkspaceLooksTheSameAsAForeignOne(): void
    {
        [$user] = $this->ownerWithWorkspace();
        $this->actAs($user);

        self::assertSame(404, $this->get('/w/' . self::DUMMY_ULID . '/team')->status);
        self::assertSame(404, $this->get('/w/' . self::DUMMY_ULID)->status);
    }

    public function testGuestsAreSentToTheLoginForm(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->useBrowser();

        foreach ($this->workspaceRoutes() as $route) {
            $path = $this->pathFor($route, $workspace->publicId);
            $response = in_array('GET', $route->methods, true) ? $this->get($path) : $this->post($path);
            self::assertSame('/login', $response->header('Location'), $path);
        }
    }

    public function testEveryRouteGivesEachRoleExactlyItsPermissions(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $users = [Role::Owner->value => $owner];
        foreach (Role::assignable() as $role) {
            $users[$role->value] = $this->memberOf($workspace, $role->value . '@example.com', $role);
        }
        $matrix = $this->app->container()->get(Permissions::class);
        $checked = 0;

        foreach ($users as $roleName => $user) {
            $this->actAs($user);
            foreach ($this->workspaceRoutes() as $route) {
                if (str_ends_with($route->pattern, '/leave')) {
                    continue; // really leaves the workspace; covered in TeamTest
                }
                $permission = $this->requiredPermission($route);
                $path = $this->pathFor($route, $workspace->publicId);
                $status = $this->send($route, $path);
                $label = $roleName . ' ' . $route->methods[0] . ' ' . $path;
                if ($permission !== null && !$matrix->allows(Role::from($roleName), $permission)) {
                    self::assertSame(403, $status, $label . ' must be forbidden (needs ' . $permission . ')');
                } else {
                    self::assertNotContains($status, [403, 404, 500], $label . ' must be reachable');
                }
                ++$checked;
            }
        }

        self::assertGreaterThan(50, $checked);
    }

    public function testWorkspaceMembershipDoesNotCrossWorkspaces(): void
    {
        [$ownerA, $a] = $this->ownerWithWorkspace('a@example.com');
        [, $b] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $adminOfA = $this->memberOf($a, 'admin@example.com', Role::Admin);
        $this->actAs($adminOfA);

        self::assertSame(200, $this->get($this->base($a) . '/team')->status);
        self::assertSame(404, $this->get($this->base($b) . '/team')->status, 'admin of A is nobody in B');
        self::assertSame(404, $this->post($this->base($b) . '/settings', ['name' => 'Взлом', 'timezone' => 'UTC', 'locale' => 'ru'])->status);
        self::assertSame('Пространство: Борис', $this->db->select('SELECT name FROM workspaces WHERE id = ?', [$b->id])[0]['name']);
        self::assertNotSame($ownerA->id, $adminOfA->id);
    }

    public function testASwitcherListsOnlyOwnWorkspaces(): void
    {
        [$ownerA, $a] = $this->ownerWithWorkspace('a@example.com');
        $this->ownerWithWorkspace('b@example.com', 'Борис');
        $this->actAs($ownerA);

        $page = $this->get($this->base($a))->body;

        self::assertStringContainsString('Пространство: Ольга', $page);
        self::assertStringNotContainsString('Пространство: Борис', $page);
    }

    public function testTemplatesHideWhatTheRoleCannotUse(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $this->actAs($viewer);

        $page = $this->get($this->base($workspace))->body;

        self::assertStringNotContainsString($this->base($workspace) . '/team', $page);
        self::assertStringNotContainsString($this->base($workspace) . '/settings', $page);
        self::assertStringNotContainsString($this->base($workspace) . '/audit', $page);
    }
}
