<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Workspace\InvitationLookup;

/**
 * Daily housekeeping for authentication tables: finished tokens, old device rows, old sign-in journal, finished workspace invitations.
 */
final class AuthMaintenance
{
    public function __construct(
        private readonly AuthTokens $tokens,
        private readonly SessionRegistry $sessions,
        private readonly LoginService $login,
        private readonly InvitationLookup $invitations,
    ) {
    }

    /**
     * @return array{tokens: int, sessions: int, attempts: int, invitations: int} deleted rows per table
     */
    public function prune(): array
    {
        return [
            'tokens' => $this->tokens->prune(),
            'sessions' => $this->sessions->prune(),
            'attempts' => $this->login->pruneJournal(),
            'invitations' => $this->invitations->prune(),
        ];
    }
}
