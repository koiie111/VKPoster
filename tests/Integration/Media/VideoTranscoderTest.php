<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Domain\Media\FfprobeVideoProbe;
use App\Domain\Media\VideoTranscoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VideoTranscoder::class)]
final class VideoTranscoderTest extends TestCase
{
    public function testItProducesAPlayableMetadataFreeH264Mp4(): void
    {
        $source = __DIR__ . '/../../Fixtures/short-video.mp4';
        $destination = (string) tempnam(sys_get_temp_dir(), 'video-variant');
        $probe = new FfprobeVideoProbe();
        try {
            (new VideoTranscoder())->transcode($source, $destination, $probe->probe($source));
            $converted = $probe->probe($destination);
            self::assertSame('h264', $converted->codec);
            self::assertGreaterThan(0, $converted->durationMs);
            self::assertSame('video/mp4', (new \finfo(FILEINFO_MIME_TYPE))->file($destination));
        } finally {
            @unlink($destination);
        }
    }
}
