<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;

/**
 * Placeholder until posts exist (stage 07): nothing uses media, so everything may be deleted.
 */
final class NullMediaUsageChecker implements MediaUsageChecker
{
    public function blockingReason(WorkspaceContext $context, Media $media): ?string
    {
        return null;
    }
}
