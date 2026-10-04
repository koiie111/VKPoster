<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Proof that the current user belongs to a workspace and in which role. Every `WorkspaceScopedRepository`
 * method demands one, so data of a workspace cannot be read or written without first resolving the
 * membership (type-level protection against forgotten `workspace_id` filters and IDOR).
 *
 * The constructor is private: the only way to get a context is from a real `Workspace` and `Membership`
 * pair (`ResolveWorkspace` middleware, or tests through the same factory).
 */
final class WorkspaceContext
{
    private function __construct(
        public readonly int $workspaceId,
        public readonly string $workspacePublicId,
        public readonly string $workspaceName,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly int $userId,
        public readonly Role $role,
        public readonly string $memberPublicId,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the membership belongs to another workspace
     */
    public static function from(Workspace $workspace, Membership $membership): self
    {
        if ($membership->workspaceId !== $workspace->id) {
            throw new \InvalidArgumentException('The membership belongs to another workspace.');
        }

        return new self($workspace->id, $workspace->publicId, $workspace->name, $workspace->timezone, $workspace->locale, $membership->userId, $membership->role, $membership->publicId);
    }
}
