<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use RuntimeException;

/**
 * A bulk import that cannot go on. The message is for the person, in Russian.
 */
final class ImportException extends RuntimeException
{
}
