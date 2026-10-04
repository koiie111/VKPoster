<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Outcome of checking an email and password.
 */
enum LoginStatus
{
    /** Credentials are fine and no second factor is needed. */
    case Success;
    /** Password is right; a TOTP or recovery code is still required. */
    case NeedsTwoFactor;
    /** Wrong email or password (the two are deliberately indistinguishable). */
    case Invalid;
    /** Too many failures; try again after `LoginResult::$retryAfter` seconds. */
    case Locked;
    /** Correct credentials, but the account is blocked by staff. */
    case Blocked;
}
