<?php

declare(strict_types=1);

namespace App\Domain\Auth\Social;

use App\Domain\User\User;

/**
 * Decision for one social sign-in attempt. `user` is the account the decision is about (signed in,
 * blocked, or the one the identity was linked to).
 */
final class SocialResult
{
    public function __construct(public readonly SocialStatus $status, public readonly ?User $user = null)
    {
    }
}
