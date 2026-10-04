<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;

/**
 * Renditions of library pictures (crop to 1:1, 4:5, 1.91:1 or 9:16, size ceiling, watermark), made on first use and
 * cached in storage next to the original. A cache entry is keyed by everything that influences the result, including
 * the watermark's file and settings, so changing a watermark never serves a stale picture.
 */
final class VariantService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaRepository $media,
        private readonly WatermarkRepository $watermarks,
        private readonly ImageProcessor $images,
        private readonly MediaService $service,
    ) {
    }

    /**
     * The stored object that realises `$spec` for `$media`, creating it when needed.
     *
     * @return array{key: string, size: int, width: int, height: int, mime: string}
     * @throws MediaException when the file cannot have this variant (not a picture, animated) or processing fails
     */
    public function get(WorkspaceContext $context, Media $media, VariantSpec $spec): array
    {
        if (!$spec->transforms()) {
            return ['key' => $media->storageKey, 'size' => $media->size, 'width' => $media->width ?? 0, 'height' => $media->height ?? 0, 'mime' => $media->mime];
        }
        if (!$media->isImage() || $media->animated) {
            throw new MediaException('Для этого файла такой вариант недоступен: обрезка и водяной знак работают только с обычными фото.');
        }
        $watermark = null;
        if ($spec->watermark) {
            $watermark = $spec->watermarkId !== null ? $this->watermarks->find($context, $spec->watermarkId) : $this->watermarks->default($context);
            if ($watermark === null) {
                throw new MediaException('Водяной знак не настроен. Загрузите логотип в разделе «Водяной знак».');
            }
        }
        $name = $this->cacheName($spec, $watermark);
        $cached = $media->variants[$name] ?? null;
        if ($cached !== null && $this->storage->exists($cached['key'])) {
            return $cached;
        }

        $temporary = [];
        try {
            $source = $this->fetch($media->storageKey, $temporary);
            $mark = null;
            if ($watermark !== null) {
                $mark = ['path' => $this->fetch($watermark->storageKey, $temporary), 'position' => $watermark->position, 'opacity' => $watermark->opacity, 'scale' => $watermark->scale, 'margin' => $watermark->margin];
            }
            $dest = $this->temp($temporary);
            $result = $this->images->render($source, $dest, $spec->ratio(), $spec->maxEdge, $spec->maxBytes, $mark);
            $stream = fopen($dest, 'rb');
            if ($stream === false) {
                throw new MediaException('Не удалось сохранить результат.');
            }
            $size = (int) filesize($dest);
            $key = preg_replace('/\.[a-z0-9]+$/', '', $media->storageKey) . '_v_' . $name . '.' . ($result['mime'] === 'image/png' ? 'png' : 'jpg');
            try {
                $this->storage->put($key, $stream);
            } finally {
                fclose($stream);
            }
        } catch (StorageException) {
            throw new MediaException('Не удалось подготовить файл. Попробуйте ещё раз чуть позже.');
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
        $entry = ['key' => $key, 'size' => $size, 'width' => $result['width'], 'height' => $result['height'], 'mime' => $result['mime']];
        $variants = $media->variants;
        $variants[$name] = $entry;
        $this->media->setVariants($context, $media, $variants);

        return $entry;
    }

    private function cacheName(VariantSpec $spec, ?Watermark $watermark): string
    {
        $parts = [$spec->crop, $spec->maxEdge, $spec->maxBytes ?? 0];
        if ($watermark !== null) {
            array_push($parts, $watermark->publicId, $watermark->storageKey, $watermark->position, $watermark->opacity, $watermark->scale, $watermark->margin);
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * @param list<string> $temporary
     */
    private function fetch(string $key, array &$temporary): string
    {
        $path = $this->temp($temporary);
        $in = $this->storage->read($key);
        $out = fopen($path, 'wb');
        if ($out === false) {
            fclose($in);
            throw new MediaException('Не удалось подготовить файл.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $path;
    }

    /**
     * @param list<string> $temporary
     */
    private function temp(array &$temporary): string
    {
        return $temporary[] = $this->service->newTempFile();
    }
}
