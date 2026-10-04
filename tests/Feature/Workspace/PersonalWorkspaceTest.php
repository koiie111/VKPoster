<?php

declare(strict_types=1);

namespace App\Tests\Feature\Workspace;

use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceService;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every account owns a personal workspace; `/app` leads into the right workspace; extra workspaces can be created.
 */
#[CoversClass(WorkspaceService::class)]
final class PersonalWorkspaceTest extends WorkspaceTestCase
{
    public function testRegistrationCreatesAPersonalWorkspaceOwnedByTheNewUser(): void
    {
        $this->post('/register', ['name' => 'Мария', 'email' => 'maria@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $rows = $this->db->select('SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id JOIN users u ON u.id = w.owner_id WHERE u.email = ?', ['maria@example.com']);
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['is_personal']);
        self::assertSame('owner', $rows[0]['role']);
        self::assertSame('Пространство: Мария', $rows[0]['name']);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $rows[0]['public_id']);
        self::assertContains('workspace.created', array_map(static fn (array $r): string => (string) $r['action'], $this->db->select('SELECT action FROM audit_log')));
    }

    public function testAppRedirectsIntoTheWorkspaceAndShowsIt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $redirect = $this->get('/app');

        self::assertSame('/w/' . $workspace->publicId, $redirect->header('Location'));
        $page = $this->follow($redirect);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Здравствуйте, Ольга!', $page->body);
        self::assertStringContainsString('Пространство: Ольга', $page->body);
    }

    public function testAnAccountWithoutAnyWorkspaceGetsOneOnFirstVisit(): void
    {
        $this->createUser('old@example.com');
        $this->signIn('old@example.com');

        $location = (string) $this->get('/app')->header('Location');

        self::assertStringStartsWith('/w/', $location);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
        self::assertSame(200, $this->get($location)->status);
    }

    public function testAppReturnsToTheLastUsedWorkspace(): void
    {
        [$owner, $personal] = $this->ownerWithWorkspace();
        $second = $this->app->container()->get(WorkspaceService::class)->create($owner, 'Кофейня «Зерно»');
        self::assertNotNull($second);
        $this->actAs($owner);

        $this->get('/w/' . $second->publicId);

        self::assertSame('/w/' . $second->publicId, $this->get('/app')->header('Location'));
        self::assertNotSame($personal->publicId, $second->publicId);
    }

    public function testNewWorkspaceCanBeCreatedAndAppearsInTheSwitcher(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        self::assertSame(200, $this->get('/workspaces/new')->status);

        $response = $this->post('/workspaces', ['name' => 'Школа йоги']);

        $location = (string) $response->header('Location');
        self::assertStringStartsWith('/w/', $location);
        $page = $this->get($location);
        self::assertStringContainsString('Школа йоги', $page->body);
        self::assertStringContainsString('Пространство «Школа йоги» создано.', $page->body);
        $row = $this->db->select('SELECT w.is_personal, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE w.name = ?', ['Школа йоги'])[0];
        self::assertSame(0, (int) $row['is_personal']);
        self::assertSame(Role::Owner->value, $row['role']);
    }

    public function testNameIsValidated(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $response = $this->post('/workspaces', ['name' => 'А']);

        self::assertSame('/workspaces/new', $response->header('Location'));
        self::assertStringContainsString('не меньше 2', $this->get('/workspaces/new')->body);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
    }

    public function testOwnedWorkspacesAreLimited(): void
    {
        [$owner, $personal] = $this->ownerWithWorkspace();
        $this->givePlan($personal, 'agency');
        $service = $this->app->container()->get(WorkspaceService::class);
        for ($i = 1; $i < WorkspaceService::OWNED_LIMIT; ++$i) {
            self::assertNotNull($service->create($owner, 'Проект ' . $i));
        }
        $this->actAs($owner);

        $this->post('/workspaces', ['name' => 'Лишнее']);

        self::assertStringContainsString('не больше ' . WorkspaceService::OWNED_LIMIT, $this->get('/workspaces/new')->body);
        self::assertSame(WorkspaceService::OWNED_LIMIT, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces')[0]['c']);
    }

    public function testCreatingAWorkspaceNeedsAConfirmedEmailAndASignedInUser(): void
    {
        self::assertSame('/login', $this->get('/workspaces/new')->header('Location'));
        self::assertSame('/login', $this->post('/workspaces', ['name' => 'Проект'])->header('Location'));
        $this->createUser('late@example.com', verified: false);
        $this->signIn('late@example.com');

        self::assertSame('/email/verification', $this->get('/workspaces/new')->header('Location'));
    }
}
