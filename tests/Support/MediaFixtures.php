<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Imagick;
use ImagickPixel;

/**
 * Builds small real files for media tests: pictures through Imagick, a minimal MP4 header, a PDF, and hostile
 * variants (code appended to a JPEG, forged dimensions).
 */
final class MediaFixtures
{
    public const PAYLOAD = '<?php system($_GET["c"]); ?>';

    public static function jpeg(int $width = 40, int $height = 20, string $color = 'red'): string
    {
        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel($color));
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }

    /**
     * A JPEG carrying a PHP payload in a comment, in EXIF-like metadata and after the end-of-image marker.
     */
    public static function polyglotJpeg(): string
    {
        $image = new Imagick();
        $image->newImage(40, 20, new ImagickPixel('blue'));
        $image->setImageFormat('jpeg');
        $image->commentImage(self::PAYLOAD);
        $image->setImageProperty('exif:Artist', self::PAYLOAD);

        return $image->getImageBlob() . self::PAYLOAD;
    }

    public static function png(int $width = 40, int $height = 20, bool $transparent = false): string
    {
        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel($transparent ? 'transparent' : 'green'));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /**
     * A PNG whose header claims much larger dimensions than the pixels it holds (a decompression-bomb shape).
     */
    public static function pngClaiming(int $width, int $height): string
    {
        $bytes = self::png(8, 8);
        // IHDR: 8-byte signature, 4-byte length, "IHDR", then width and height as big-endian 32-bit integers.
        return substr($bytes, 0, 16) . pack('NN', $width, $height) . substr($bytes, 24);
    }

    public static function animatedGif(int $frames = 3): string
    {
        $gif = new Imagick();
        $gif->setFormat('gif');
        foreach (['red', 'green', 'blue', 'yellow'] as $i => $color) {
            if ($i >= $frames) {
                break;
            }
            $frame = new Imagick();
            $frame->newImage(30, 20, new ImagickPixel($color));
            $frame->setImageFormat('gif');
            $frame->setImageDelay(10);
            $gif->addImage($frame);
        }

        return $gif->getImagesBlob();
    }

    /**
     * A JPEG with an EXIF APP1 segment that carries the orientation tag (ImageMagick will not write one itself).
     */
    public static function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $jpeg = self::jpeg($width, $height, 'purple');
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('vv', $orientation, 0) . pack('V', 0);
        $app1 = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * Just enough of an MP4 (an `ftyp` box) for content-type detection to say video/mp4.
     */
    public static function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 2048);
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    }
}
