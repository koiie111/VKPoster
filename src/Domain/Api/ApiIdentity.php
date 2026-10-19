<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Workspace\WorkspaceContext;

/** A token's live workspace membership and explicitly granted scopes; no bearer secret retained. */
final class ApiIdentity
{
    /** @param list<string> $scopes */
    public function __construct(public readonly int $tokenId, public readonly WorkspaceContext $context, public readonly array $scopes)
    {
    }

    public function requireScope(string $scope): void
    {
        if (!in_array($scope, $this->scopes, true)) {
            throw new ApiException(403, 'insufficient_scope', 'Недостаточно прав токена.');
        }
    }
}
