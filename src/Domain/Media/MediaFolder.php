<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * A folder of the library (flat, one level). `count` is the number of files inside.
 */
final class MediaFolder
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $name,
        public readonly int $count = 0,
    ) {
    }
}
