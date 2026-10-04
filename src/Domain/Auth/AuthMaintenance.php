<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Daily housekeeping for authentication tables: finished tokens, old device rows, old sign-in journal.
 */
final class AuthMaintenance
{
    public function __construct(
        private readonly AuthTokens $tokens,
        private readonly SessionRegistry $sessions,
        private readonly LoginService $login,
    ) {
    }

    /**
     * @return array{tokens: int, sessions: int, attempts: int} deleted rows per table
     */
    public function prune(): array
    {
        return [
            'tokens' => $this->tokens->prune(),
            'sessions' => $this->sessions->prune(),
            'attempts' => $this->login->pruneJournal(),
        ];
    }
}
