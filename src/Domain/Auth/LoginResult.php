<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User\User;

/**
 * Result of `LoginService::attempt()`. `user` is set for Success, NeedsTwoFactor and Blocked.
 */
final class LoginResult
{
    public function __construct(
        public readonly LoginStatus $status,
        public readonly ?User $user = null,
        public readonly int $retryAfter = 0,
    ) {
    }
}
