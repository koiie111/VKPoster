<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Outcome of a team action (invite, change a role, remove, leave, transfer), mapped to a message by the controller.
 */
enum TeamResult
{
    case Done;
    case NotFound;
    case Forbidden;
    case AlreadyMember;
    case OwnerMustTransfer;
    case Throttled;
    case Invalid;
}
