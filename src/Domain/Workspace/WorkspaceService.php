<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\SubscriptionService;
use App\Domain\User\User;

/**
 * Creating, renaming and deleting workspaces. Everyone gets a personal workspace at sign-up, which starts with a trial of a paid plan;
 * more can be created by hand, as many as the person's plans allow (and never more than `OWNED_LIMIT`). Extra workspaces start on Free.
 */
final class WorkspaceService
{
    public const OWNED_LIMIT = 10;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
        private readonly Entitlements $entitlements,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function createPersonal(User $user): Workspace
    {
        $workspace = $this->workspaces->create($user->id, self::personalName($user), $user->timezone, true);
        $this->subscriptions->startTrial($workspace->id);
        $this->audit->record('workspace.created', $user->id, 'workspace', $workspace->publicId, ['personal' => true], $workspace->id);

        return $workspace;
    }

    /**
     * The workspace to open for a user who has none selected: their first one, creating the personal one
     * if they somehow have none (older accounts, deleted workspaces).
     */
    public function homeFor(User $user): Workspace
    {
        $mine = $this->workspaces->forUser($user->id);

        return $mine !== [] ? $mine[0]['workspace'] : $this->createPersonal($user);
    }

    /**
     * Create an additional workspace. Null when the person already owns `OWNED_LIMIT` of them.
     *
     * @throws \App\Domain\Billing\PlanLimitException when the person's plans allow no more workspaces
     */
    public function create(User $user, string $name): ?Workspace
    {
        if ($this->workspaces->countOwnedBy($user->id) >= self::OWNED_LIMIT) {
            return null;
        }
        $this->entitlements->assertCanCreateWorkspace($user->id);
        $workspace = $this->workspaces->create($user->id, $name, $user->timezone);
        $this->subscriptions->startFree($workspace->id);
        $this->audit->record('workspace.created', $user->id, 'workspace', $workspace->publicId, [], $workspace->id);

        return $workspace;
    }

    /**
     * @return bool false when the member's role may not change settings
     */
    public function update(WorkspaceContext $context, string $name, string $timezone, string $locale): bool
    {
        if (!$this->permissions->allows($context->role, 'workspace.settings')) {
            return false;
        }
        $this->workspaces->update($context, $name, $timezone, $locale);
        $this->audit->record('workspace.updated', $context->userId, 'workspace', $context->workspacePublicId, ['name' => $name, 'timezone' => $timezone, 'locale' => $locale], $context->workspaceId);

        return true;
    }

    /**
     * @return bool false when the member's role may not delete the workspace (only the owner may)
     */
    public function delete(WorkspaceContext $context): bool
    {
        if (!$this->permissions->allows($context->role, 'workspace.delete')) {
            return false;
        }
        $this->workspaces->delete($context);
        // The workspace row is gone, but the trail stays (audit_log has no foreign keys).
        $this->audit->record('workspace.deleted', $context->userId, 'workspace', $context->workspacePublicId, ['name' => $context->workspaceName], $context->workspaceId);

        return true;
    }

    public static function personalName(User $user): string
    {
        $name = trim($user->name);

        return $name === '' ? 'Моё пространство' : 'Пространство: ' . mb_substr($name, 0, 60);
    }
}
