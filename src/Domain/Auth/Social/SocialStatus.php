<?php

declare(strict_types=1);

namespace App\Domain\Auth\Social;

/**
 * What `SocialAuthService::resolve()` decided for a provider profile.
 */
enum SocialStatus
{
    /** The provider account is known: sign its owner in. */
    case SignedIn;
    /** The provider account is known but its owner is blocked. */
    case Blocked;
    /** A signed-in user attached the provider account to their own account. */
    case Linked;
    /** A signed-in user already has exactly this provider account. */
    case AlreadyLinked;
    /** A signed-in user tried to attach a provider account that belongs to somebody else. */
    case BelongsToOther;
    /** A signed-in user already has another account of this provider. */
    case ProviderSlotTaken;
    /** The provider vouches for an email that already has an account: do not merge, ask for the password. */
    case EmailExists;
    /** Nobody knows this provider account yet: offer to create an account (after consent). */
    case NewAccount;
}
