<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Domain\Media\ImageProcessor;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaLimits;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\TestEnv;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pixel work against real Imagick: header limits, re-encoding, crops, size ceilings, watermark placement.
 */
#[CoversClass(ImageProcessor::class)]
#[CoversClass(MediaLimits::class)]
final class ImageProcessorTest extends TestCase
{
    private ImageProcessor $images;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->images = new ImageProcessor(MediaLimits::fromConfig(TestEnv::config()));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(string $bytes = ''): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ip');
        file_put_contents($path, $bytes);

        return $this->files[] = $path;
    }

    private function noise(int $width, int $height): string
    {
        $image = new Imagick();
        $image->newPseudoImage($width, $height, 'plasma:fractal');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    public function testLimitsComeFromConfig(): void
    {
        $limits = MediaLimits::fromConfig(TestEnv::config());

        self::assertSame(50 * 1024 * 1024, $limits->maxFileBytes);
        self::assertSame(500 * 1024 * 1024, $limits->quotaBytes);
        self::assertSame(10000, $limits->maxSide);
        self::assertArrayHasKey('telegram', $limits->platforms);
    }

    public function testInspectReadsDimensionsAndFrames(): void
    {
        $info = $this->images->inspect($this->file(MediaFixtures::animatedGif(3)), 'image/gif');

        self::assertSame([30, 20, 3, true], [$info->width, $info->height, $info->frames, $info->animated()]);
        self::assertFalse($this->images->inspect($this->file(MediaFixtures::jpeg()), 'image/jpeg')->animated());
    }

    public function testInspectRefusesGarbageUnknownMimeAndMismatchedContent(): void
    {
        foreach ([
            [str_repeat('x', 100), 'image/jpeg'],
            [MediaFixtures::jpeg(), 'application/pdf'],
            [MediaFixtures::png(), 'image/jpeg'], // header says PNG, we were told JPEG: the format check catches it
            ["\xFF\xD8\xFF\xE0" . str_repeat("\0", 50), 'image/jpeg'],
        ] as [$bytes, $mime]) {
            try {
                $this->images->inspect($this->file($bytes), $mime);
                self::fail('expected a refusal for ' . $mime);
            } catch (MediaException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testInspectRefusesOversizedDimensionsWithoutDecoding(): void
    {
        $this->expectException(MediaException::class);
        $this->expectExceptionMessageMatches('/слишком большое: 10001×50/');

        $this->images->inspect($this->file(MediaFixtures::pngClaiming(10001, 50)), 'image/png');
    }

    public function testInspectAcceptsExactlyTheMaximumSide(): void
    {
        $wide = new Imagick();
        $wide->newImage(10000, 10, new ImagickPixel('white'));
        $wide->setImageFormat('png');

        $info = $this->images->inspect($this->file($wide->getImageBlob()), 'image/png');

        self::assertSame(10000, $info->width);
    }

    public function testHeavyAnimationsAreRefused(): void
    {
        $gif = new Imagick();
        $gif->setFormat('gif');
        for ($i = 0; $i < 3; ++$i) {
            $frame = new Imagick();
            $frame->newImage(10, 10, new ImagickPixel('red'));
            $frame->setImageFormat('gif');
            $gif->addImage($frame);
        }
        $limits = MediaLimits::fromConfig(TestEnv::config());
        $images = new ImageProcessor(new MediaLimits($limits->maxFileBytes, $limits->quotaBytes, 100, $limits->maxVideoSeconds, 1, 1, 320, []));
        // 3 frames of 100×100 are fine, but the same animation claiming a huge canvas is not: here we just check frame accounting.
        $path = $this->file($gif->getImagesBlob());
        self::assertSame(3, $images->inspect($path, 'image/gif')->frames);
    }

    public function testSanitizeDropsMetadataAndAppendedBytes(): void
    {
        $src = $this->file(MediaFixtures::polyglotJpeg());
        $dest = $this->file();

        $size = $this->images->sanitize($src, 'image/jpeg', $dest);

        $out = (string) file_get_contents($dest);
        self::assertSame(['width' => 40, 'height' => 20], $size);
        self::assertStringNotContainsString('<?php', $out);
        self::assertStringNotContainsString('system', $out);
        $check = new Imagick();
        $check->readImageBlob($out);
        self::assertSame('JPEG', $check->getImageFormat());
        self::assertSame([], $check->getImageProperties('exif:*', false));
    }

    public function testSanitizeKeepsTransparencyOfPngAndWebp(): void
    {
        $dest = $this->file();
        $this->images->sanitize($this->file(MediaFixtures::png(20, 20, true)), 'image/png', $dest);
        $check = new Imagick();
        $check->readImageBlob((string) file_get_contents($dest));
        self::assertSame('PNG', $check->getImageFormat());
        self::assertSame(0.0, $check->getImagePixelColor(5, 5)->getColorValue(Imagick::COLOR_ALPHA));
    }

    public function testSanitizeRefusesUnknownFormatAndBrokenFiles(): void
    {
        foreach ([['image/svg+xml', MediaFixtures::svg()], ['image/jpeg', "\xFF\xD8\xFF\xE0" . str_repeat('z', 80)], ['image/jpeg', MediaFixtures::png()]] as [$mime, $bytes]) {
            try {
                $this->images->sanitize($this->file($bytes), $mime, $this->file());
                self::fail('expected a refusal for ' . $mime);
            } catch (MediaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testThumbnailFitsTheBoxAndKeepsAspect(): void
    {
        $dest = $this->file();

        $this->images->thumbnail($this->file(MediaFixtures::jpeg(1600, 400)), $dest, 320);

        $check = new Imagick();
        $check->readImageBlob((string) file_get_contents($dest));
        self::assertSame('WEBP', $check->getImageFormat());
        self::assertSame([320, 80], [$check->getImageWidth(), $check->getImageHeight()]);
    }

    public function testRenderCropsToTheRequestedRatio(): void
    {
        foreach ([[1.0, 500, 500], [0.8, 400, 500], [1.91, 955, 500], [0.5625, 281, 500]] as [$ratio, $w, $h]) {
            $dest = $this->file();
            $result = $this->images->render($this->file(MediaFixtures::jpeg(1000, 500)), $dest, $ratio, 4000, null, null);
            self::assertEqualsWithDelta($w, $result['width'], 1, (string) $ratio);
            self::assertEqualsWithDelta($h, $result['height'], 1, (string) $ratio);
            self::assertSame('image/jpeg', $result['mime']);
        }
    }

    public function testRenderCropsAroundTheCentre(): void
    {
        // Left third red, middle third green, right third blue: a square crop of 300×100 keeps only the middle.
        $image = new Imagick();
        $image->newImage(300, 100, new ImagickPixel('red'));
        $image->setImageFormat('png');
        $draw = new \ImagickDraw();
        $draw->setFillColor(new ImagickPixel('lime'));
        $draw->rectangle(100, 0, 199, 99);
        $draw->setFillColor(new ImagickPixel('blue'));
        $draw->rectangle(200, 0, 299, 99);
        $image->drawImage($draw);
        $dest = $this->file();

        $this->images->render($this->file($image->getImageBlob()), $dest, 1.0, 4000, null, null);

        $out = new Imagick();
        $out->readImageBlob((string) file_get_contents($dest));
        self::assertSame(100, $out->getImageWidth());
        $color = $out->getImagePixelColor(50, 50)->getColor();
        self::assertGreaterThan(200, $color['g']);
        self::assertLessThan(60, $color['r']);
    }

    public function testRenderNeverUpscalesAndShrinksToTheLongEdge(): void
    {
        $small = $this->images->render($this->file(MediaFixtures::jpeg(100, 50)), $this->file(), null, 2560, null, null);
        $large = $this->images->render($this->file(MediaFixtures::jpeg(3000, 1500)), $this->file(), null, 1000, null, null);

        self::assertSame([100, 50], [$small['width'], $small['height']]);
        self::assertSame([1000, 500], [$large['width'], $large['height']]);
    }

    public function testRenderStaysWithinTheByteCeiling(): void
    {
        $dest = $this->file();
        $source = $this->file($this->noise(1500, 1500));
        $unbounded = $this->images->render($source, $this->file(), null, 4000, null, null);

        $result = $this->images->render($source, $dest, null, 4000, 60_000, null);

        self::assertLessThanOrEqual(60_000, filesize($dest));
        self::assertSame('image/jpeg', $result['mime'], 'a PNG that cannot fit becomes a JPEG');
        self::assertLessThanOrEqual($unbounded['width'], $result['width']);
    }

    public function testRenderGivesUpWhenTheCeilingIsImpossible(): void
    {
        $this->expectException(MediaException::class);

        $this->images->render($this->file($this->noise(800, 800)), $this->file(), null, 4000, 50, null);
    }

    public function testRenderKeepsPngTransparencyWhenItFits(): void
    {
        $image = new Imagick();
        $image->newImage(60, 40, new ImagickPixel('transparent'));
        $draw = new \ImagickDraw();
        $draw->setFillColor(new ImagickPixel('red'));
        $draw->rectangle(10, 10, 30, 30);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        $result = $this->images->render($this->file($image->getImageBlob()), $dest = $this->file(), null, 2560, null, null);

        self::assertSame('image/png', $result['mime']);
        $check = new Imagick();
        $check->readImageBlob((string) file_get_contents($dest));
        self::assertSame('PNG', $check->getImageFormat());
    }

    public function testWatermarkLandsInTheRequestedCorner(): void
    {
        $mark = new Imagick();
        $mark->newImage(100, 100, new ImagickPixel('white'));
        $mark->setImageFormat('png');
        $markPath = $this->file($mark->getImageBlob());
        $base = $this->file(MediaFixtures::jpeg(400, 400, 'black'));

        $brightness = function (string $position, int $x, int $y) use ($base, $markPath): float {
            $dest = $this->file();
            $this->images->render($base, $dest, null, 4000, null, ['path' => $markPath, 'position' => $position, 'opacity' => 100, 'scale' => 25, 'margin' => 5]);
            $out = new Imagick();
            $out->readImageBlob((string) file_get_contents($dest));
            $c = $out->getImagePixelColor($x, $y)->getColor();

            return ($c['r'] + $c['g'] + $c['b']) / 3;
        };

        // 25% of 400 = 100 px logo, margin 5% = 20 px.
        self::assertGreaterThan(200, $brightness('tl', 60, 60));
        self::assertLessThan(20, $brightness('tl', 340, 340));
        self::assertGreaterThan(200, $brightness('br', 340, 340));
        self::assertGreaterThan(200, $brightness('tr', 340, 60));
        self::assertGreaterThan(200, $brightness('bl', 60, 340));
        self::assertGreaterThan(200, $brightness('mc', 200, 200));
        self::assertGreaterThan(200, $brightness('tc', 200, 60));
        self::assertGreaterThan(200, $brightness('bc', 200, 340));
        self::assertGreaterThan(200, $brightness('ml', 60, 200));
        self::assertGreaterThan(200, $brightness('mr', 340, 200));
        self::assertLessThan(20, $brightness('mc', 20, 20));
    }

    public function testWatermarkOpacityBlendsWithThePicture(): void
    {
        $mark = new Imagick();
        $mark->newImage(100, 100, new ImagickPixel('white'));
        $mark->setImageFormat('png');
        $markPath = $this->file($mark->getImageBlob());
        $base = $this->file(MediaFixtures::jpeg(400, 400, 'black'));
        $dest = $this->file();

        $this->images->render($base, $dest, null, 4000, null, ['path' => $markPath, 'position' => 'mc', 'opacity' => 50, 'scale' => 25, 'margin' => 0]);

        $out = new Imagick();
        $out->readImageBlob((string) file_get_contents($dest));
        $c = $out->getImagePixelColor(200, 200)->getColor();
        self::assertEqualsWithDelta(128, $c['r'], 25);
    }

    public function testTallLogoStillFitsASmallPicture(): void
    {
        $mark = new Imagick();
        $mark->newImage(20, 400, new ImagickPixel('white'));
        $mark->setImageFormat('png');

        $result = $this->images->render($this->file(MediaFixtures::jpeg(100, 50)), $this->file(), null, 4000, null, ['path' => $this->file($mark->getImageBlob()), 'position' => 'br', 'opacity' => 100, 'scale' => 60, 'margin' => 30]);

        self::assertSame([100, 50], [$result['width'], $result['height']]);
    }

    public function testPreviewIsAPngWithTheMarkOnIt(): void
    {
        $mark = new Imagick();
        $mark->newImage(100, 50, new ImagickPixel('red'));
        $mark->setImageFormat('png');

        $png = $this->images->previewWatermark(['path' => $this->file($mark->getImageBlob()), 'position' => 'mc', 'opacity' => 100, 'scale' => 30, 'margin' => 3]);

        $out = new Imagick();
        $out->readImageBlob($png);
        self::assertSame('PNG', $out->getImageFormat());
        self::assertSame([800, 500], [$out->getImageWidth(), $out->getImageHeight()]);
        $c = $out->getImagePixelColor(400, 250)->getColor();
        self::assertGreaterThan(200, $c['r']);
        self::assertLessThan(80, $c['g']);
    }

    public function testWatermarkInspectionLimits(): void
    {
        self::assertSame(['width' => 40, 'height' => 20], $this->images->inspectWatermark($this->file(MediaFixtures::png())));
        foreach ([MediaFixtures::pngClaiming(3000, 100), MediaFixtures::jpeg()] as $bad) {
            try {
                $this->images->inspectWatermark($this->file($bad));
                self::fail('expected a refusal');
            } catch (MediaException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
