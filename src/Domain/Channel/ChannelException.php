<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use RuntimeException;

/**
 * A channel action the person cannot complete; the message is Russian and meant to be shown as is.
 */
final class ChannelException extends RuntimeException
{
}
