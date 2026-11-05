<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Billing\Entitlements;
use App\Domain\Settings\ShortVideoLimits;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Http\UploadedFile;
use App\Support\Clock;
use Symfony\Component\Uid\Ulid;

/** Resumable, sequential video uploads in private storage. Every request rechecks the workspace and uploader. */
final class ChunkedVideoUploads
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly MediaService $media,
        private readonly MediaRepository $repository,
        private readonly Entitlements $entitlements,
        private readonly ShortVideoLimits $limits,
        private readonly VideoProbe $video,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{id: string, offset: int, chunk_bytes: int} */
    public function start(WorkspaceContext $context, string $name, int $size, string $sha256): array
    {
        if ($size < 1 || $size > $this->limits->maxFileBytes()) {
            throw new MediaException(sprintf('Короткое видео должно быть не больше %d МиБ.', $this->limits->maxFileMiB()));
        }
        $planMiB = $this->entitlements->limit($context->workspaceId, 'short_video_file_mib');
        if ($planMiB !== null && $size > $planMiB * 1024 * 1024) {
            throw new MediaException('Это ограничение тарифа: видео слишком большое. Выберите файл поменьше или тариф с большим лимитом.', true);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new MediaException('Не удалось проверить контрольную сумму видео. Выберите файл заново.');
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 150) {
            throw new MediaException('Название файла должно быть от 1 до 150 символов.');
        }
        $quota = $this->media->quotaFor($context);
        if ($quota !== null && $this->repository->usedBytes($context) + $size > $quota) {
            throw new MediaException('В медиатеке не хватает места для видео. Удалите ненужные файлы или выберите тариф с большим местом.');
        }
        $id = (string) new Ulid();
        $this->ensureDirectory();
        $metadata = [
            'workspace_id' => $context->workspaceId,
            'user_id' => $context->userId,
            'name' => $name,
            'size' => $size,
            'sha256' => $sha256,
            'offset' => 0,
            'expires_at' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
        ];
        $handle = fopen($this->path($id, 'json'), 'x');
        if ($handle === false) {
            throw new MediaException('Не удалось начать загрузку видео. Попробуйте ещё раз.');
        }
        try {
            fwrite($handle, json_encode($metadata, JSON_THROW_ON_ERROR));
        } finally {
            fclose($handle);
        }

        return ['id' => $id, 'offset' => 0, 'chunk_bytes' => self::CHUNK_BYTES];
    }

    /** @return array{offset: int, size: int} */
    public function status(WorkspaceContext $context, string $id): array
    {
        return $this->locked($context, $id, static fn (array $metadata): array => ['offset' => (int) $metadata['offset'], 'size' => (int) $metadata['size']], false);
    }

    /** @return array{offset: int, size: int} */
    public function append(WorkspaceContext $context, string $id, int $offset, UploadedFile $chunk): array
    {
        if (!$chunk->isValid() || $chunk->size < 1 || $chunk->size > self::CHUNK_BYTES) {
            throw new MediaException('Часть видео не загрузилась или слишком велика. Повторите отправку.');
        }

        return $this->locked($context, $id, function (array &$metadata) use ($id, $offset, $chunk): array {
            $current = (int) $metadata['offset'];
            $size = (int) $metadata['size'];
            if ($offset !== $current) {
                return ['offset' => $current, 'size' => $size];
            }
            if ($offset + $chunk->size > $size || $size > $this->limits->maxFileBytes()) {
                throw new MediaException('Размер видео превышает разрешённый предел.');
            }
            $source = fopen($chunk->path, 'rb');
            $target = fopen($this->path($id, 'part'), 'c+b');
            if ($source === false || $target === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                throw new MediaException('Не удалось записать часть видео. Повторите отправку.');
            }
            try {
                if (ftruncate($target, max(0, $current)) === false || fseek($target, $current) !== 0 || stream_copy_to_stream($source, $target) !== $chunk->size) {
                    throw new MediaException('Не удалось записать часть видео. Повторите отправку.');
                }
                fflush($target);
            } finally {
                fclose($source);
                fclose($target);
            }
            $metadata['offset'] = $current + $chunk->size;
            return ['offset' => $metadata['offset'], 'size' => $size];
        }, true);
    }

    public function finish(WorkspaceContext $context, string $id, ?string $folderId = null): UploadResult
    {
        return $this->locked($context, $id, function (array $metadata) use ($context, $id, $folderId): UploadResult {
            $part = $this->path($id, 'part');
            if ((int) $metadata['offset'] !== (int) $metadata['size'] || filesize($part) !== (int) $metadata['size']) {
                throw new MediaException('Видео загружено не полностью. Продолжите загрузку.');
            }
            $actual = hash_file('sha256', $part);
            if ($actual === false || !hash_equals((string) $metadata['sha256'], $actual)) {
                throw new MediaException('Контрольная сумма видео не совпала. Загрузите файл заново.');
            }
            if (!in_array(MediaService::sniff($part), ['video/mp4', 'video/quicktime'], true)) {
                throw new MediaException('Выберите видеофайл MP4 или MOV.');
            }
            $info = $this->video->probe($part);
            if ($info->durationMs > $this->limits->maxDurationMs()) {
                throw new MediaException(sprintf('Короткое видео должно быть не длиннее %d секунд.', $this->limits->maxDurationSeconds()));
            }
            $result = $this->media->upload($context, $part, (string) $metadata['name'], $folderId);
            @unlink($part);
            return $result;
        }, false, true);
    }

    /** Remove abandoned partial files after their one-day resume window. */
    public function pruneExpired(): int
    {
        $removed = 0;
        $files = glob($this->path('*', 'json'));
        foreach ($files === false ? [] : $files as $path) {
            $id = basename($path, '.json');
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
                continue;
            }
            $handle = @fopen($path, 'r+');
            if ($handle === false) {
                continue;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $raw = stream_get_contents($handle);
                $metadata = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($metadata) && (int) ($metadata['expires_at'] ?? 0) > $this->clock->now()->getTimestamp()) {
                    continue;
                }
                @unlink($this->path($id, 'part'));
                @unlink($path);
                ++$removed;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        return $removed;
    }

    /** @param callable(array<string, mixed>&): mixed $action */
    private function locked(WorkspaceContext $context, string $id, callable $action, bool $write, bool $delete = false): mixed
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            throw new MediaException('Загрузка видео не найдена. Начните заново.');
        }
        $path = $this->path($id, 'json');
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            throw new MediaException('Загрузка видео не найдена. Начните заново.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new MediaException('Загрузка видео занята. Попробуйте ещё раз.');
            }
            $raw = stream_get_contents($handle);
            $metadata = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($metadata) || ($metadata['workspace_id'] ?? null) !== $context->workspaceId || ($metadata['user_id'] ?? null) !== $context->userId || (int) ($metadata['expires_at'] ?? 0) <= $this->clock->now()->getTimestamp()) {
                throw new MediaException('Загрузка видео не найдена. Начните заново.');
            }
            $result = $action($metadata);
            if ($delete) {
                @unlink($path);
            } elseif ($write) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($metadata, JSON_THROW_ON_ERROR));
                fflush($handle);
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path('a', 'json'));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new MediaException('Не удалось подготовить загрузку видео. Попробуйте позже.');
        }
    }

    private function path(string $id, string $suffix): string
    {
        return dirname(__DIR__, 3) . '/storage/video-uploads/' . $id . '.' . $suffix;
    }
}
