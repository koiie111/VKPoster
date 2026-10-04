<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Purpose of a one-time token. A token only works for the purpose it was issued for.
 */
enum TokenType: string
{
    case EmailVerify = 'email_verify';
    case PasswordReset = 'password_reset';
    case EmailChange = 'email_change';
    case Remember = 'remember';
}
