<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use RuntimeException;

/**
 * A feed that could not be fetched or read. The message is for the person, in Russian.
 */
final class FeedException extends RuntimeException
{
}
