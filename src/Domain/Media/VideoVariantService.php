<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;

/** Builds and caches a network-safe MP4 when an original short video needs transcoding. */
final class VideoVariantService
{
    public const SLUG = 'video-h264';

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaRepository $repository,
        private readonly MediaService $media,
        private readonly VideoProbe $probe,
        private readonly VideoTranscoder $transcoder,
    ) {
    }

    /** @return array{key: string, size: int, width: int, height: int, mime: string, slug: string} */
    public function get(WorkspaceContext $context, Media $item): array
    {
        if ($item->kind !== MediaKind::Video) {
            throw new MediaException('Для перекодирования выберите видео.');
        }
        $cached = $item->variants[self::SLUG] ?? null;
        if ($cached !== null && $this->storage->exists($cached['key'])) {
            return $cached + ['slug' => self::SLUG];
        }
        $source = $this->media->newTempFile();
        $destination = $this->media->newTempFile();
        try {
            $input = $this->storage->read($item->storageKey);
            $output = fopen($source, 'wb');
            if ($output === false) {
                fclose($input);
                throw new MediaException('Не удалось подготовить видео к публикации.');
            }
            try {
                stream_copy_to_stream($input, $output);
            } finally {
                fclose($input);
                fclose($output);
            }
            $info = $this->probe->probe($source);
            $this->transcoder->transcode($source, $destination, $info);
            $converted = $this->probe->probe($destination);
            if ($converted->codec !== 'h264' || ($converted->hasAudio && $converted->audioCodec !== 'aac') || ($converted->frameRate !== null && $converted->frameRate > 60) || $converted->width < 1 || $converted->height < 1) {
                throw new MediaException('Видео не удалось подготовить для соцсети. Попробуйте другой файл.');
            }
            $key = (preg_replace('/\.[a-z0-9]+$/', '', $item->storageKey) ?? $item->storageKey) . '_video_h264.mp4';
            $stream = fopen($destination, 'rb');
            if ($stream === false) {
                throw new MediaException('Не удалось сохранить подготовленное видео.');
            }
            try {
                $this->storage->put($key, $stream);
            } finally {
                fclose($stream);
            }
            $entry = ['key' => $key, 'size' => (int) filesize($destination), 'width' => $converted->width, 'height' => $converted->height, 'mime' => 'video/mp4'];
            $variants = $item->variants;
            $variants[self::SLUG] = $entry;
            $this->repository->setVariants($context, $item, $variants);
            return $entry + ['slug' => self::SLUG];
        } catch (StorageException) {
            throw new MediaException('Не удалось прочитать или сохранить видео. Попробуйте позже.');
        } finally {
            @unlink($source);
            @unlink($destination);
        }
    }
}
