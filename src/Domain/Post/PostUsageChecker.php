<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Media\Media;
use App\Domain\Media\MediaUsageChecker;
use App\Domain\Workspace\WorkspaceContext;

/**
 * Refuses to delete a library file that a scheduled or publishing post still needs. Published posts do not count: the network
 * keeps its own copy.
 */
final class PostUsageChecker implements MediaUsageChecker
{
    public function __construct(private readonly PostRepository $posts)
    {
    }

    public function blockingReason(WorkspaceContext $context, Media $media): ?string
    {
        $count = $this->posts->plannedWithMedia($context, $media->publicId);

        return $count === 0 ? null : ($count === 1 ? 'Файл используется в запланированном посте.' : sprintf('Файл используется в запланированных постах: %d.', $count)) . ' Сначала измените или отмените эти посты.';
    }
}
