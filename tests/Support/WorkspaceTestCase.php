<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\User\User;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Domain\Workspace\WorkspaceService;

/**
 * Base class for workspace tests: users with personal workspaces, extra members in chosen roles, and
 * "browsers" signed in as a given user.
 */
abstract class WorkspaceTestCase extends AuthTestCase
{
    protected WorkspaceRepository $workspaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaces = $this->app->container()->get(WorkspaceRepository::class);
    }

    /**
     * A confirmed user together with their personal workspace (they own it).
     *
     * @return array{User, Workspace}
     */
    protected function ownerWithWorkspace(string $email = 'owner@example.com', string $name = 'Ольга'): array
    {
        $user = $this->createUser($email, true, null, $name);
        $workspace = $this->app->container()->get(WorkspaceService::class)->createPersonal($user);

        return [$user, $workspace];
    }

    protected function memberOf(Workspace $workspace, string $email, Role $role, string $name = 'Участник'): User
    {
        $user = $this->createUser($email, true, null, $name);
        self::assertTrue($this->workspaces->addMember($workspace->id, $user->id, $role, $workspace->ownerId));

        return $user;
    }

    protected function contextFor(Workspace $workspace, User $user): WorkspaceContext
    {
        $membership = $this->workspaces->membership($workspace->id, $user->id);
        self::assertNotNull($membership);

        return WorkspaceContext::from($workspace, $membership);
    }

    /**
     * Sign the user in inside a fresh browser and keep using it.
     */
    protected function actAs(User $user): void
    {
        $this->useBrowser();
        $response = $this->signIn((string) $user->email);
        self::assertSame('/app', $response->header('Location'), 'sign-in of ' . $user->email);
    }

    /**
     * @return list<string> audit actions of a workspace, oldest first
     */
    protected function auditActions(Workspace $workspace): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            $this->db->select('SELECT action FROM audit_log WHERE workspace_id = ? ORDER BY id ASC', [$workspace->id]),
        );
    }

    protected function base(Workspace $workspace): string
    {
        return '/w/' . $workspace->publicId;
    }
}
