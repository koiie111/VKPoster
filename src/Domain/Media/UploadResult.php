<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Outcome of an upload: the library item and whether the same file was already there (nothing new was stored).
 */
final class UploadResult
{
    public function __construct(public readonly Media $media, public readonly bool $duplicate)
    {
    }
}
