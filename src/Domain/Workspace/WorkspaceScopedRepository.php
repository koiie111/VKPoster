<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Kernel\Database\Connection;
use App\Kernel\Database\QueryBuilder;

/**
 * Base class for every repository that stores data owned by a workspace. Rules, enforced by the
 * `WorkspaceScopedRepositoryRule` PHPStan check:
 * - every public method takes a `WorkspaceContext` as its first parameter;
 * - queries start from `scoped()`, which already filters by the context's `workspace_id`.
 *
 * Lookups that by nature happen before a context exists (finding a workspace, an invitation by its
 * token) live in separate, clearly named classes.
 */
abstract class WorkspaceScopedRepository
{
    public function __construct(protected readonly Connection $db)
    {
    }

    /**
     * Query builder for `$table` limited to the workspace of `$context`.
     */
    protected function scoped(WorkspaceContext $context, string $table): QueryBuilder
    {
        return $this->db->table($table)->where('workspace_id', '=', $context->workspaceId);
    }
}
