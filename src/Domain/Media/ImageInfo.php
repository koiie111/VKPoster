<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Facts about an image file read from its header, without decoding the pixels.
 */
final class ImageInfo
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $frames,
    ) {
    }

    public function animated(): bool
    {
        return $this->frames > 1;
    }
}
