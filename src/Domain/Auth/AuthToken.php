<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * A validated one-time token row: who it belongs to and the data stored with it (e.g. the new email).
 */
final class AuthToken
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly TokenType $type,
        public readonly array $payload,
    ) {
    }
}
