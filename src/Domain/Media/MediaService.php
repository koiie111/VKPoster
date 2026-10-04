<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;
use App\Support\Clock;
use Symfony\Component\Uid\Ulid;

/**
 * The upload pipeline and deletion. An upload is checked in this order, cheapest first: size, quota, real
 * content type (never the name or the browser's claim), duplicate, then kind-specific inspection (image header
 * limits, video probe, PDF signature), re-encoding of images, previews. Anything failing raises a `MediaException`
 * with a message for the user; nothing is stored in that case.
 */
final class MediaService
{
    /** MIME type (detected from content) => extension. This is also the allow-list. */
    public const TYPES = [
        'image/jpeg' => ['jpg', MediaKind::Image],
        'image/png' => ['png', MediaKind::Image],
        'image/webp' => ['webp', MediaKind::Image],
        'image/gif' => ['gif', MediaKind::Image],
        'video/mp4' => ['mp4', MediaKind::Video],
        'video/quicktime' => ['mov', MediaKind::Video],
        'application/pdf' => ['pdf', MediaKind::Document],
    ];

    public function __construct(
        private readonly MediaRepository $media,
        private readonly FolderRepository $folders,
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
        private readonly VideoProbe $video,
        private readonly MediaLimits $limits,
        private readonly MediaUsageChecker $usage,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly Entitlements $entitlements,
        private readonly string $tmpDir,
    ) {
    }

    /**
     * Add a file from the local disk to the library. `$clientName` is only remembered as the display name.
     *
     * @throws MediaException
     */
    public function upload(WorkspaceContext $context, string $path, string $clientName, ?string $folderPublicId = null, ?string $verifiedMime = null): UploadResult
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            throw new MediaException('Файл пустой или не загрузился. Попробуйте ещё раз.');
        }
        if ($size > $this->limits->maxFileBytes) {
            throw new MediaException(sprintf('Файл больше %s МБ.', self::megabytes($this->limits->maxFileBytes)));
        }
        $this->assertQuota($context, $size);

        $mime = $verifiedMime ?? self::sniff($path);
        if (!isset(self::TYPES[$mime])) {
            throw new MediaException('Этот тип файла не поддерживается. Можно загрузить JPG, PNG, WebP, GIF, MP4, MOV и PDF.');
        }
        [$extension, $kind] = self::TYPES[$mime];

        $folderId = null;
        if ($folderPublicId !== null && $folderPublicId !== '') {
            $folder = $this->folders->find($context, $folderPublicId) ?? throw new MediaException('Такой папки нет.');
            $folderId = $folder->id;
        }

        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new MediaException('Не удалось прочитать файл.');
        }
        $existing = $this->media->findBySha($context, $sha);
        if ($existing !== null) {
            return new UploadResult($existing, true);
        }

        $temporary = [];
        try {
            $stored = $path;
            $width = $height = $duration = $codec = null;
            $animated = false;
            $thumb = $this->tempFile($temporary);
            $hasThumb = false;
            switch ($kind) {
                case MediaKind::Image:
                    $info = $this->images->inspect($path, $mime);
                    $stored = $this->tempFile($temporary);
                    $dimensions = $this->images->sanitize($path, $mime, $stored);
                    [$width, $height, $animated] = [$dimensions['width'], $dimensions['height'], $info->animated()];
                    $this->images->thumbnail($stored, $thumb, $this->limits->thumbSize);
                    $hasThumb = true;
                    break;
                case MediaKind::Video:
                    $info = $this->video->probe($path);
                    if ($info->durationMs < 1) {
                        throw new MediaException('Не удалось определить длину видео.');
                    }
                    if ($info->durationMs > $this->limits->maxVideoSeconds * 1000) {
                        throw new MediaException(sprintf('Видео длиннее %d минут.', intdiv($this->limits->maxVideoSeconds, 60)));
                    }
                    if ($info->width > 7680 || $info->height > 7680) {
                        throw new MediaException('Разрешение видео слишком большое.');
                    }
                    [$width, $height, $duration, $codec] = [$info->width, $info->height, $info->durationMs, $info->codec];
                    $hasThumb = $this->video->frame($path, $thumb, $this->limits->thumbSize, min(1.0, $info->durationMs / 2000));
                    break;
                case MediaKind::Document:
                    if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                        throw new MediaException('Файл не похож на PDF.');
                    }
                    break;
            }

            $storedSize = filesize($stored);
            if ($storedSize === false) {
                throw new MediaException('Не удалось сохранить файл.');
            }
            if ($storedSize !== $size) {
                $this->assertQuota($context, $storedSize);
            }

            $publicId = MediaRepository::newPublicId();
            $base = sprintf('ws/%d/%s/%s', $context->workspaceId, $this->clock->now()->format('Y/m'), strtolower($publicId));
            $key = $base . '.' . $extension;
            $thumbKey = $hasThumb ? $base . '_thumb.' . ($kind === MediaKind::Image ? 'webp' : 'jpg') : null;
            $this->store($key, $stored);
            if ($thumbKey !== null) {
                $this->store($thumbKey, $thumb);
            }
            try {
                $item = $this->media->insert($context, [
                    'uploader_id' => $context->userId,
                    'folder_id' => $folderId,
                    'kind' => $kind,
                    'original_name' => self::displayName($clientName, $extension),
                    'storage_key' => $key,
                    'thumb_key' => $thumbKey,
                    'mime' => $mime,
                    'size' => $storedSize,
                    'width' => $width,
                    'height' => $height,
                    'duration_ms' => $duration,
                    'codec' => $codec,
                    'animated' => $animated,
                    'sha256' => $sha,
                ], $publicId);
            } catch (\Throwable $e) {
                $this->forget($key);
                if ($thumbKey !== null) {
                    $this->forget($thumbKey);
                }
                // Two identical uploads at once: the other request won, report its copy.
                if ($e instanceof \PDOException && $e->getCode() === '23000') {
                    $winner = $this->media->findBySha($context, $sha);
                    if ($winner !== null) {
                        return new UploadResult($winner, true);
                    }
                }
                throw $e;
            }

            return new UploadResult($item, false);
        } catch (StorageException) {
            throw new MediaException('Не удалось сохранить файл. Попробуйте ещё раз чуть позже.');
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Delete a library item with its preview and cached variants.
     *
     * @throws MediaException when something unpublished still uses the file
     */
    public function delete(WorkspaceContext $context, Media $media): void
    {
        $reason = $this->usage->blockingReason($context, $media);
        if ($reason !== null) {
            throw new MediaException($reason);
        }
        $this->media->delete($context, $media);
        foreach ([$media->storageKey, $media->thumbKey, ...array_map(static fn (array $v): string => $v['key'], array_values($media->variants))] as $key) {
            if ($key !== null) {
                $this->forget($key);
            }
        }
        $this->audit->record('media.deleted', $context->userId, 'media', $media->publicId, ['name' => $media->originalName], $context->workspaceId);
    }

    /**
     * Content type from the bytes (`finfo`), or an empty string.
     */
    public static function sniff(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === false ? '' : $mime;
    }

    public function newTempFile(): string
    {
        $file = tempnam($this->tmpDir, 'media');
        if ($file === false) {
            throw new MediaException('Не удалось подготовить файл. Попробуйте ещё раз.');
        }

        return $file;
    }

    /**
     * @throws MediaException
     */
    private function assertQuota(WorkspaceContext $context, int $incoming): void
    {
        $quota = $this->quotaFor($context);
        if ($quota !== null && $this->media->usedBytes($context) + $incoming > $quota) {
            throw new MediaException(sprintf('В медиатеке не хватает места: лимит %s. Удалите ненужные файлы или перейдите на тариф с большим местом.', MediaPresenter::size($quota)), true);
        }
    }

    /**
     * The library size the plan allows (null = unlimited), lowered by the optional global cap from `MEDIA_QUOTA_MB`.
     */
    public function quotaFor(WorkspaceContext $context): ?int
    {
        return $this->limits->capQuota($this->entitlements->storageBytes($context->workspaceId));
    }

    /**
     * @param list<string> $temporary registry of temp files to remove afterwards
     */
    private function tempFile(array &$temporary): string
    {
        return $temporary[] = $this->newTempFile();
    }

    private function store(string $key, string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new StorageException('Could not open a temp file.');
        }
        try {
            $this->storage->put($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function forget(string $key): void
    {
        try {
            $this->storage->delete($key);
        } catch (StorageException) {
            // An orphaned object is harmless (random name, unreachable); the row is what matters.
        }
    }

    /**
     * Display name: client file name without path, control characters or a double extension trick, at most 255 bytes.
     */
    public static function displayName(string $clientName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file.' . $extension;
        }
        while (strlen($name) > 255) {
            $name = mb_substr($name, 0, mb_strlen($name) - 1);
        }

        return $name;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    }
}
