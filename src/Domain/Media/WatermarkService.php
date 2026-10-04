<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;
use Symfony\Component\Uid\Ulid;

/**
 * Watermarks: upload of the logo (PNG only, re-encoded like any picture), placement settings, the default
 * choice, deletion and the settings-page preview.
 */
final class WatermarkService
{
    public const MAX_PER_WORKSPACE = 10;
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly WatermarkRepository $watermarks,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
        private readonly MediaService $service,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @throws MediaException
     */
    public function create(WorkspaceContext $context, string $name, string $path): Watermark
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new MediaException('Логотип должен быть файлом PNG не больше 2 МБ.');
        }
        if (MediaService::sniff($path) !== 'image/png') {
            throw new MediaException('Логотип должен быть в формате PNG (лучше с прозрачным фоном).');
        }
        if (count($this->watermarks->all($context)) >= self::MAX_PER_WORKSPACE) {
            throw new MediaException(sprintf('Можно сохранить не больше %d водяных знаков. Удалите лишние.', self::MAX_PER_WORKSPACE));
        }
        $this->images->inspectWatermark($path);
        $clean = $this->service->newTempFile();
        try {
            $dimensions = $this->images->sanitize($path, 'image/png', $clean);
            $key = sprintf('ws/%d/watermarks/%s.png', $context->workspaceId, strtolower((string) new Ulid()));
            $stream = fopen($clean, 'rb');
            if ($stream === false) {
                throw new MediaException('Не удалось сохранить логотип.');
            }
            try {
                $this->storage->put($key, $stream);
            } finally {
                fclose($stream);
            }
        } catch (StorageException) {
            throw new MediaException('Не удалось сохранить логотип. Попробуйте ещё раз.');
        } finally {
            @unlink($clean);
        }
        $watermark = $this->watermarks->create($context, $name, $key, $dimensions['width'], $dimensions['height']);
        $this->audit->record('media.watermark_created', $context->userId, 'watermark', $watermark->publicId, ['name' => $name], $context->workspaceId);

        return $watermark;
    }

    public function update(WorkspaceContext $context, Watermark $watermark, string $name, string $position, int $opacity, int $scale, int $margin, bool $makeDefault): void
    {
        $this->watermarks->updateSettings($context, $watermark, $name, $position, $opacity, $scale, $margin);
        if ($makeDefault && !$watermark->isDefault) {
            $this->watermarks->makeDefault($context, $watermark);
        }
        $this->audit->record('media.watermark_updated', $context->userId, 'watermark', $watermark->publicId, ['name' => $name], $context->workspaceId);
    }

    public function delete(WorkspaceContext $context, Watermark $watermark): void
    {
        $this->watermarks->delete($context, $watermark);
        try {
            $this->storage->delete($watermark->storageKey);
        } catch (StorageException) {
            // The row is gone, the file is unreachable: nothing more to do.
        }
        $this->audit->record('media.watermark_deleted', $context->userId, 'watermark', $watermark->publicId, ['name' => $watermark->name], $context->workspaceId);
    }

    /**
     * PNG preview of the watermark on a neutral picture, with the settings given (not necessarily saved yet).
     *
     * @throws MediaException
     */
    public function preview(Watermark $watermark, string $position, int $opacity, int $scale, int $margin): string
    {
        $path = $this->service->newTempFile();
        try {
            $in = $this->storage->read($watermark->storageKey);
            $out = fopen($path, 'wb');
            if ($out === false) {
                fclose($in);
                throw new MediaException('Не удалось построить предпросмотр.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            return $this->images->previewWatermark(['path' => $path, 'position' => $position, 'opacity' => $opacity, 'scale' => $scale, 'margin' => $margin]);
        } catch (StorageException) {
            throw new MediaException('Не удалось построить предпросмотр.');
        } finally {
            @unlink($path);
        }
    }
}
