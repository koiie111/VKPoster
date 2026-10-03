<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

use RuntimeException;

/**
 * Raised when configuration is missing or unsafe for the current environment.
 * Messages name the variable only, never its value.
 */
final class ConfigException extends RuntimeException
{
}
