<?php

declare(strict_types=1);

namespace App\Domain\Media;

use RuntimeException;

/**
 * A media operation was refused for a reason the user can fix. The message is written for the user
 * (Russian, no technical details) and may be shown as is.
 */
final class MediaException extends RuntimeException
{
}
