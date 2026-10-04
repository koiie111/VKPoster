<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use RuntimeException;

/**
 * A provider refused the sign-in or sent something we cannot trust (bad code, bad token signature,
 * unexpected reply). The message is for logs only; users see a generic text.
 */
final class OAuthException extends RuntimeException
{
}
