<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * How the publishing pipeline must react to a platform error (see master plan §4.4).
 */
enum ErrorKind: string
{
    /** Nothing was sent (5xx, no connection): retry with backoff. */
    case Temporary = 'temporary';
    /** The platform refuses this content or target: retrying is pointless. */
    case Permanent = 'permanent';
    /** The token is wrong or revoked, or the bot lost its rights: the channel needs attention. */
    case Auth = 'auth';
    /** Too many requests: retry after `PlatformError::$retryAfter` seconds. */
    case RateLimited = 'rate_limited';
    /** The request may have reached the platform (timeout after sending): never retry blindly, a duplicate post is worse. */
    case UnknownOutcome = 'unknown_outcome';
}
