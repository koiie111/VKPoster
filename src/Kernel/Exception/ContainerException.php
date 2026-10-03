<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

use RuntimeException;

/**
 * Raised when the container cannot build a service (unknown class, unresolvable parameter, cycle).
 */
final class ContainerException extends RuntimeException
{
}
