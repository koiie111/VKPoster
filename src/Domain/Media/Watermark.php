<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * A watermark image (PNG logo) with placement settings. `opacity`, `scale` (share of the picture width) and
 * `margin` (share of the shorter side) are percentages; `position` is one of `Watermark::POSITIONS`.
 */
final class Watermark
{
    /** Nine placements as row (t/m/b) + column (l/c/r). */
    public const POSITIONS = ['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'];

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $name,
        public readonly string $storageKey,
        public readonly int $width,
        public readonly int $height,
        public readonly string $position,
        public readonly int $opacity,
        public readonly int $scale,
        public readonly int $margin,
        public readonly bool $isDefault,
    ) {
    }
}
