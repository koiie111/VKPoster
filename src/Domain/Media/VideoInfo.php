<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * What `ffprobe` says about a video: size after rotation, length, codec and whether there is sound.
 */
final class VideoInfo
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $durationMs,
        public readonly string $codec,
        public readonly bool $hasAudio,
    ) {
    }
}
