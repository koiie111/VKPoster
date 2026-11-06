<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

/** A semantic provider that uses only the requesting workspace's own credentials when configured. */
interface WorkspaceSemanticSelectionProvider extends SemanticSelectionProvider
{
    public function evaluateForWorkspace(int $workspaceId, SemanticSelectionInput $input): SemanticSelectionResult;
    public function usesOwnKey(int $workspaceId): bool;
    public function availableForWorkspace(int $workspaceId): bool;
}
