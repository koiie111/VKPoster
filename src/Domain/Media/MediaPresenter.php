<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Turns a library item into plain values for templates and JSON (sizes and durations in human form, URLs).
 * Internal storage keys are never included.
 */
final class MediaPresenter
{
    public function __construct(private readonly MediaUrls $urls)
    {
    }

    /**
     * @return array{id: string, name: string, kind: string, kind_label: string, thumb: ?string, url: string, size: string, dimensions: ?string, duration: ?string, animated: bool, mime: string}
     */
    public function present(Media $media): array
    {
        return [
            'id' => $media->publicId,
            'name' => $media->originalName,
            'kind' => $media->kind->value,
            'kind_label' => $media->kind->label(),
            'thumb' => $media->hasThumb() ? $this->urls->path($media, 'thumb') : null,
            'url' => $this->urls->path($media),
            'size' => self::size($media->size),
            'dimensions' => $media->width !== null && $media->height !== null ? $media->width . '×' . $media->height : null,
            'duration' => $media->durationMs === null ? null : self::duration($media->durationMs),
            'animated' => $media->animated,
            'mime' => $media->mime,
        ];
    }

    public static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, $bytes < 10 * 1024 ? 1 : 0, ',', ' ') . ' КБ';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1048576, $bytes < 10 * 1048576 ? 1 : 0, ',', ' ') . ' МБ';
        }

        return number_format($bytes / 1073741824, 1, ',', ' ') . ' ГБ';
    }

    public static function duration(int $milliseconds): string
    {
        $seconds = (int) round($milliseconds / 1000);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
