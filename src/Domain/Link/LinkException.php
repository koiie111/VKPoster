<?php

declare(strict_types=1);

namespace App\Domain\Link;

use RuntimeException;

/** A short link was refused; the message is in Russian and safe to show. */
final class LinkException extends RuntimeException
{
}
