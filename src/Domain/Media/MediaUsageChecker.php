<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;

/**
 * Tells whether a library item is still needed by something that has not been published yet (a scheduled post),
 * so that deleting it can be refused. Stage 07 (posts) provides the real implementation.
 */
interface MediaUsageChecker
{
    /**
     * @return string|null a short user-facing reason ("используется в запланированном посте…") or null when the file is free
     */
    public function blockingReason(WorkspaceContext $context, Media $media): ?string;
}
