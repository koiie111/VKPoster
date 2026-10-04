<?php

declare(strict_types=1);

namespace App\Integrations\Storage;

use RuntimeException;

/**
 * The storage backend failed (disk full, S3 unreachable, missing object).
 */
final class StorageException extends RuntimeException
{
}
