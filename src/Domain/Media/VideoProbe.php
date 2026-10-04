<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Looks inside video files. The real implementation runs `ffprobe`/`ffmpeg` (`FfprobeVideoProbe`); tests use a fake.
 */
interface VideoProbe
{
    /**
     * @throws MediaException when the file is not a playable video
     */
    public function probe(string $path): VideoInfo;

    /**
     * Save a still frame (JPEG, longest side at most `$box`) to `$dest`.
     *
     * @return bool false when no frame could be extracted
     */
    public function frame(string $path, string $dest, int $box, float $atSeconds): bool;
}
