<?php

declare(strict_types=1);

namespace App\Domain\Media;

use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * All pixel work, on Imagick: header checks (against decompression bombs), re-encoding of uploads
 * (drops every byte of the original container, including metadata and appended payloads), thumbnails,
 * crops, size limits and watermarks. Source paths always come from us (a temp file or the storage cache),
 * never from user input, and the format is checked with `pingImage` before anything is decoded.
 */
final class ImageProcessor
{
    private const FORMATS = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP', 'image/gif' => 'GIF'];

    /** Total pixels across all frames of an animation. */
    private const MAX_ANIMATION_PIXELS = 400_000_000;
    private const MAX_FRAMES = 500;

    public function __construct(private readonly MediaLimits $limits)
    {
    }

    /**
     * Read size and frame count from the header and refuse oversized or unreadable images.
     *
     * @throws MediaException
     */
    public function inspect(string $path, string $mime): ImageInfo
    {
        $format = self::FORMATS[$mime] ?? null;
        $size = $format === null ? false : @getimagesize($path);
        if ($format === null || $size === false || $size[0] < 1 || $size[1] < 1) {
            throw new MediaException('Не удалось прочитать изображение. Файл повреждён или это не картинка.');
        }
        [$width, $height] = [$size[0], $size[1]];
        if ($width > $this->limits->maxSide || $height > $this->limits->maxSide) {
            throw new MediaException(sprintf('Изображение слишком большое: %d×%d. Допустимо не больше %d×%d пикселей.', $width, $height, $this->limits->maxSide, $this->limits->maxSide));
        }
        $frames = 1;
        try {
            $this->limit();
            $probe = new Imagick();
            $probe->pingImage($path);
            if ($probe->getImageFormat() !== $format) {
                throw new MediaException('Не удалось прочитать изображение. Файл повреждён или это не картинка.');
            }
            $frames = $probe->getNumberImages();
            $probe->clear();
        } catch (ImagickException) {
            throw new MediaException('Не удалось прочитать изображение. Файл повреждён или это не картинка.');
        }
        if ($frames > 1 && $format !== 'GIF') {
            throw new MediaException('Анимированные изображения принимаем только в формате GIF (или загрузите видео MP4).');
        }
        if ($frames > self::MAX_FRAMES || $frames * $width * $height > self::MAX_ANIMATION_PIXELS) {
            throw new MediaException('Анимация слишком тяжёлая. Сократите число кадров или размер.');
        }

        return new ImageInfo($width, $height, $frames);
    }

    /**
     * Re-encode an upload into `$dest`: applies the EXIF orientation, converts CMYK to sRGB, and strips all
     * metadata. The result is built from decoded pixels only, so nothing hidden in the original survives.
     *
     * @return array{width: int, height: int}
     * @throws MediaException
     */
    public function sanitize(string $src, string $mime, string $dest): array
    {
        $format = self::FORMATS[$mime] ?? throw new MediaException('Этот формат изображений не поддерживается.');
        try {
            $this->limit();
            $image = new Imagick();
            $image->readImage($src);
            if ($image->getImageFormat() !== $format) {
                throw new MediaException('Не удалось прочитать изображение. Файл повреждён или это не картинка.');
            }
            if ($format === 'GIF' && $image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                foreach ($image as $frame) {
                    $frame->stripImage();
                }
                $image->setFirstIterator();
                $image->writeImages('gif:' . $dest, true);
                $size = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
                $image->clear();

                return $size;
            }
            $image->setFirstIterator();
            $image->autoOrient();
            if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
            $image->stripImage();
            $this->setEncoding($image, $format, 88);
            $image->writeImage(strtolower($format) . ':' . $dest);
            $size = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            $image->clear();

            return $size;
        } catch (ImagickException) {
            throw new MediaException('Не удалось обработать изображение. Попробуйте сохранить его заново и загрузить ещё раз.');
        }
    }

    /**
     * Preview that fits into `$box` × `$box` (WebP, first frame, transparency kept).
     *
     * @throws MediaException
     */
    public function thumbnail(string $src, string $dest, int $box): void
    {
        try {
            $this->limit();
            $image = new Imagick();
            $image->readImage($src . '[0]');
            $image->autoOrient();
            $image->stripImage();
            $image->thumbnailImage($box, $box, true);
            $this->setEncoding($image, 'WEBP', 80);
            $image->writeImage('webp:' . $dest);
            $image->clear();
        } catch (ImagickException) {
            throw new MediaException('Не удалось создать превью.');
        }
    }

    /**
     * Cut the largest centred rectangle of aspect `$ratio` (width / height) out of the picture, shrink it so
     * that the long side is at most `$maxEdge`, optionally stamp a watermark, and encode it so that the file is
     * at most `$maxBytes`. JPEG stays JPEG; pictures with transparency stay PNG unless they cannot fit.
     *
     * @param array{path: string, position: string, opacity: int, scale: int, margin: int}|null $watermark
     * @return array{width: int, height: int, mime: string}
     * @throws MediaException
     */
    public function render(string $src, string $dest, ?float $ratio, int $maxEdge, ?int $maxBytes, ?array $watermark): array
    {
        try {
            $this->limit();
            $image = new Imagick();
            $image->readImage($src . '[0]');
            $hasAlpha = $image->getImageAlphaChannel() && $this->hasTransparency($image);
            if ($ratio !== null) {
                $this->cropToRatio($image, $ratio);
            }
            $longEdge = max($image->getImageWidth(), $image->getImageHeight());
            if ($longEdge > $maxEdge) {
                $scale = $maxEdge / $longEdge;
                $image->resizeImage(max(1, (int) round($image->getImageWidth() * $scale)), max(1, (int) round($image->getImageHeight() * $scale)), Imagick::FILTER_LANCZOS, 1);
            }
            if ($watermark !== null) {
                $this->stamp($image, $watermark);
            }
            $mime = $hasAlpha ? 'image/png' : 'image/jpeg';
            if ($mime === 'image/jpeg' && $image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor(new ImagickPixel('white'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            $blob = $this->encode($image, $mime, 88);
            if ($maxBytes !== null) {
                for ($attempt = 0; strlen($blob) > $maxBytes && $attempt < 10; ++$attempt) {
                    if ($mime === 'image/png') {
                        $mime = 'image/jpeg'; // a PNG cannot be squeezed by quality: give up transparency
                        $image->setImageBackgroundColor(new ImagickPixel('white'));
                        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                        $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                        $blob = $this->encode($image, $mime, 82);
                        continue;
                    }
                    if ($attempt < 3) {
                        $blob = $this->encode($image, $mime, 82 - $attempt * 10);
                        continue;
                    }
                    $image->resizeImage(max(1, (int) round($image->getImageWidth() * 0.85)), max(1, (int) round($image->getImageHeight() * 0.85)), Imagick::FILTER_LANCZOS, 1);
                    $blob = $this->encode($image, $mime, 70);
                }
                if (strlen($blob) > $maxBytes) {
                    throw new MediaException('Не удалось уменьшить файл до нужного размера.');
                }
            }
            $result = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight(), 'mime' => $mime];
            $image->clear();
            if (file_put_contents($dest, $blob) === false) {
                throw new MediaException('Не удалось сохранить результат.');
            }

            return $result;
        } catch (ImagickException) {
            throw new MediaException('Не удалось обработать изображение.');
        }
    }

    /**
     * A grey gradient canvas with the watermark on it, as PNG bytes (the settings page preview).
     *
     * @param array{path: string, position: string, opacity: int, scale: int, margin: int} $watermark
     * @throws MediaException
     */
    public function previewWatermark(array $watermark, int $width = 800, int $height = 500): string
    {
        try {
            $this->limit();
            $canvas = new Imagick();
            $canvas->newPseudoImage($width, $height, 'gradient:#e2e8f0-#64748b');
            $canvas->setImageFormat('png');
            $this->stamp($canvas, $watermark);
            $blob = $canvas->getImageBlob();
            $canvas->clear();

            return $blob;
        } catch (ImagickException) {
            throw new MediaException('Не удалось построить предпросмотр.');
        }
    }

    /**
     * Check that a watermark upload is a PNG of reasonable size and return its dimensions.
     *
     * @return array{width: int, height: int}
     * @throws MediaException
     */
    public function inspectWatermark(string $path): array
    {
        $info = $this->inspect($path, 'image/png');
        if ($info->width > 2000 || $info->height > 2000) {
            throw new MediaException('Логотип слишком большой. Допустимо не больше 2000×2000 пикселей.');
        }
        if ($info->animated()) {
            throw new MediaException('Логотип должен быть обычной картинкой PNG.');
        }

        return ['width' => $info->width, 'height' => $info->height];
    }

    private function cropToRatio(Imagick $image, float $ratio): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if ($width / $height > $ratio) {
            $cropW = (int) round($height * $ratio);
            $cropH = $height;
        } else {
            $cropW = $width;
            $cropH = (int) round($width / $ratio);
        }
        $cropW = max(1, min($width, $cropW));
        $cropH = max(1, min($height, $cropH));
        $image->cropImage($cropW, $cropH, intdiv($width - $cropW, 2), intdiv($height - $cropH, 2));
        $image->setImagePage(0, 0, 0, 0);
    }

    /**
     * @param array{path: string, position: string, opacity: int, scale: int, margin: int} $settings
     */
    private function stamp(Imagick $image, array $settings): void
    {
        $mark = new Imagick();
        $mark->readImage($settings['path'] . '[0]');
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $targetW = max(1, (int) round($width * min(max($settings['scale'], 1), 100) / 100));
        $targetH = max(1, (int) round($mark->getImageHeight() * $targetW / max(1, $mark->getImageWidth())));
        if ($targetH > $height) { // a tall logo must still fit
            $targetH = $height;
            $targetW = max(1, (int) round($mark->getImageWidth() * $targetH / max(1, $mark->getImageHeight())));
        }
        $mark->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS, 1);
        $mark->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, min(max($settings['opacity'], 0), 100) / 100, Imagick::CHANNEL_ALPHA);
        $margin = (int) round(min($width, $height) * min(max($settings['margin'], 0), 30) / 100);
        $position = $settings['position'];
        $x = match ($position[1] ?? 'r') {
            'l' => $margin,
            'c' => intdiv($width - $targetW, 2),
            default => $width - $targetW - $margin,
        };
        $y = match ($position[0]) {
            't' => $margin,
            'm' => intdiv($height - $targetH, 2),
            default => $height - $targetH - $margin,
        };
        $image->compositeImage($mark, Imagick::COMPOSITE_OVER, max(0, $x), max(0, $y));
        $mark->clear();
    }

    private function hasTransparency(Imagick $image): bool
    {
        // A uniform alpha channel is either fully opaque (common for PNG exports) or invisible: both count as opaque.
        $range = $image->getImageChannelRange(Imagick::CHANNEL_ALPHA);

        return $range['minima'] < $range['maxima'];
    }

    private function setEncoding(Imagick $image, string $format, int $quality): void
    {
        $image->setImageFormat(strtolower($format));
        if ($format === 'JPEG') {
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($quality);
            $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        } elseif ($format === 'WEBP') {
            $image->setImageCompressionQuality($quality);
        }
    }

    private function encode(Imagick $image, string $mime, int $quality): string
    {
        $clone = clone $image;
        $clone->stripImage();
        $this->setEncoding($clone, $mime === 'image/png' ? 'PNG' : 'JPEG', $quality);
        $blob = $clone->getImageBlob();
        $clone->clear();

        return $blob;
    }

    /**
     * Keep a hostile image from eating the server: cap memory, mapped files, pixel area, disk and time.
     */
    private function limit(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, $this->limits->maxSide * $this->limits->maxSide + 1_000_000);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 2 * 1024 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);
    }
}
