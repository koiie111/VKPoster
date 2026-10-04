<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Outcome of accepting an invitation.
 */
enum AcceptStatus
{
    case Joined;
    case AlreadyMember;
    case Invalid;
    case EmailMismatch;
}
