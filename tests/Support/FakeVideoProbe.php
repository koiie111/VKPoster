<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Media\MediaException;
use App\Domain\Media\VideoInfo;
use App\Domain\Media\VideoProbe;

/**
 * `VideoProbe` that returns canned facts, so video upload tests do not depend on ffmpeg.
 */
final class FakeVideoProbe implements VideoProbe
{
    public ?VideoInfo $info;
    public bool $frameAvailable = true;

    public function __construct(?VideoInfo $info = null)
    {
        $this->info = $info ?? new VideoInfo(1280, 720, 12_500, 'h264', true);
    }

    public function probe(string $path): VideoInfo
    {
        return $this->info ?? throw new MediaException('Не удалось прочитать видео.');
    }

    public function frame(string $path, string $dest, int $box, float $atSeconds): bool
    {
        if (!$this->frameAvailable) {
            return false;
        }
        file_put_contents($dest, MediaFixtures::jpeg(64, 36));

        return true;
    }
}
