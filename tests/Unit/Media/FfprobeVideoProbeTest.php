<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Domain\Media\FfprobeVideoProbe;
use App\Domain\Media\MediaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The ffmpeg-backed probe against real tiny videos (skipped where ffmpeg is not installed; the Docker image has it).
 */
#[CoversClass(FfprobeVideoProbe::class)]
final class FfprobeVideoProbeTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v ffmpeg')) === '' || trim((string) shell_exec('command -v ffprobe')) === '') {
            self::markTestSkipped('ffmpeg is not installed');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function temp(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/ff' . bin2hex(random_bytes(6)) . $suffix;

        return $this->files[] = $path;
    }

    private function makeVideo(int $w, int $h, float $seconds, bool $audio = true, string $extra = ''): string
    {
        $path = $this->temp('.mp4');
        $cmd = sprintf(
            'ffmpeg -v error -y -f lavfi -i testsrc=size=%dx%d:rate=10 %s -t %s -pix_fmt yuv420p -c:v libx264 %s %s 2>&1',
            $w,
            $h,
            $audio ? '-f lavfi -i sine=frequency=440 -c:a aac -shortest' : '',
            (string) $seconds,
            $extra,
            escapeshellarg($path),
        );
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        return $path;
    }

    public function testProbeReadsSizeLengthCodecAndAudio(): void
    {
        $info = (new FfprobeVideoProbe())->probe($this->makeVideo(320, 180, 2.0));

        self::assertSame([320, 180, 'h264', true], [$info->width, $info->height, $info->codec, $info->hasAudio]);
        self::assertEqualsWithDelta(2000, $info->durationMs, 300);
    }

    public function testSilentVideoHasNoAudio(): void
    {
        self::assertFalse((new FfprobeVideoProbe())->probe($this->makeVideo(160, 90, 1.0, false))->hasAudio);
    }

    public function testRotationSwapsTheSize(): void
    {
        $path = $this->makeVideo(320, 180, 1.0, false);
        $rotated = $this->temp('.mp4');
        exec(sprintf('ffmpeg -v error -y -i %s -c copy -metadata:s:v rotate=90 %s 2>&1', escapeshellarg($path), escapeshellarg($rotated)), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        $info = (new FfprobeVideoProbe())->probe($rotated);

        self::assertSame([180, 320], [$info->width, $info->height]);
    }

    public function testFrameIsAJpegThatFitsTheBox(): void
    {
        $dest = $this->temp('.jpg');

        $ok = (new FfprobeVideoProbe())->frame($this->makeVideo(640, 360, 2.0), $dest, 320, 1.0);

        self::assertTrue($ok);
        $size = getimagesize($dest);
        self::assertIsArray($size);
        self::assertSame([320, 180, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
    }

    public function testFrameFailsCleanlyForGarbage(): void
    {
        $garbage = $this->temp('.mp4');
        file_put_contents($garbage, str_repeat('not a video', 100));

        self::assertFalse((new FfprobeVideoProbe())->frame($garbage, $this->temp('.jpg'), 320, 0.0));
    }

    public function testProbeRefusesGarbageAndAudioOnlyFiles(): void
    {
        $garbage = $this->temp('.mp4');
        file_put_contents($garbage, str_repeat('not a video', 100));
        $audio = $this->temp('.m4a');
        exec(sprintf('ffmpeg -v error -y -f lavfi -i sine=frequency=440 -t 1 -c:a aac %s 2>&1', escapeshellarg($audio)), $out, $code);
        self::assertSame(0, $code);

        foreach ([$garbage, $audio] as $path) {
            try {
                (new FfprobeVideoProbe())->probe($path);
                self::fail('expected a refusal');
            } catch (MediaException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testMissingBinaryIsAFriendlyError(): void
    {
        $this->expectException(MediaException::class);

        (new FfprobeVideoProbe('/nonexistent/ffprobe'))->probe($this->makeVideo(64, 64, 1.0, false));
    }

    public function testTextFilesNeverReachNetworkProtocols(): void
    {
        // A playlist pointing at a network address must not be followed (protocol whitelist is "file").
        $playlist = $this->temp('.m3u8');
        file_put_contents($playlist, "#EXTM3U\n#EXTINF:1,\nhttp://127.0.0.1:1/never.ts\n");

        $this->expectException(MediaException::class);

        (new FfprobeVideoProbe(timeoutSeconds: 10))->probe($playlist);
    }
}
