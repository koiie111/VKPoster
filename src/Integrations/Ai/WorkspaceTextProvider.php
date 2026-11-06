<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Domain\Content\Processing\TextSettings;

/** A text provider that chooses workspace credentials without storing mutable request context. */
interface WorkspaceTextProvider extends TextProvider
{
    public function generateForWorkspace(int $workspaceId, string $text, TextSettings $settings): string;
    public function usesOwnKey(int $workspaceId): bool;
    public function availableForWorkspace(int $workspaceId): bool;
}
