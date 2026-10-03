<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

use RuntimeException;

/**
 * A user-supplied URL points somewhere we must not fetch (private network, bad scheme, ...).
 */
final class SsrfException extends RuntimeException
{
}
