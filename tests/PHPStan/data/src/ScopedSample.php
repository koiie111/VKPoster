<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Data;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;

/**
 * Fixture for the workspace-scoping rule.
 */
final class GoodRepository extends WorkspaceScopedRepository
{
    public function all(WorkspaceContext $context): int
    {
        return $context->workspaceId;
    }

    public static function helper(int $x): int
    {
        return $x;
    }

    private function internal(int $x): int
    {
        return $x;
    }
}

/**
 * Fixture for the workspace-scoping rule.
 */
final class BadRepository extends WorkspaceScopedRepository
{
    public function all(int $workspaceId): int
    {
        return $workspaceId;
    }

    public function none(): int
    {
        return 0;
    }

    public function late(int $x, WorkspaceContext $context): int
    {
        return $x + $context->workspaceId;
    }
}
