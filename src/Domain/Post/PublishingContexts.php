<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;

/**
 * A workspace context for background work, where no member is signed in. The post's author is the member the work is done for;
 * when they have left the workspace, the owner stands in, so a scheduled post is still published after its author is gone.
 */
final class PublishingContexts
{
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    public function forPost(Post $post): ?WorkspaceContext
    {
        $workspace = $this->workspaces->findById($post->workspaceId);
        if ($workspace === null) {
            return null;
        }
        foreach ([$post->authorId, $workspace->ownerId] as $userId) {
            $membership = $userId === null ? null : $this->workspaces->membership($workspace->id, $userId);
            if ($membership !== null) {
                return WorkspaceContext::from($workspace, $membership);
            }
        }

        return null;
    }
}
