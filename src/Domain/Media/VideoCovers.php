<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;

/** Extract a chosen frame with ffmpeg and keep it as an ordinary workspace image. */
final class VideoCovers
{
    public function __construct(private readonly MediaStorage $storage, private readonly MediaService $media)
    {
    }

    public function capture(WorkspaceContext $context, Media $video, int $millisecond): UploadResult
    {
        if ($video->kind !== MediaKind::Video || $millisecond < 0 || $millisecond >= ($video->durationMs ?? 0)) {
            throw new MediaException('Выберите кадр внутри видео.');
        }
        $source = $this->media->newTempFile();
        $target = $this->media->newTempFile();
        try {
            $in = $this->storage->read($video->storageKey);
            $out = fopen($source, 'wb');
            if ($out === false) {
                fclose($in);
                throw new MediaException('Не удалось подготовить кадр. Попробуйте позже.');
            }
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }
            $args = ['ffmpeg', '-v', 'error', '-nostdin', '-y', '-protocol_whitelist', 'file', '-ss', sprintf('%.3f', $millisecond / 1000),
                '-i', $source, '-frames:v', '1', '-vf', 'scale=720:-2', '-map_metadata', '-1', '-c:v', 'mjpeg', '-f', 'image2', $target];
            $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new MediaException('Не удалось извлечь кадр из видео.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 30;
            $exit = 1;
            try {
                while (true) {
                    stream_get_contents($pipes[1]);
                    stream_get_contents($pipes[2]);
                    $state = proc_get_status($process);
                    if (!$state['running']) {
                        $exit = $state['exitcode'];
                        break;
                    }
                    if (microtime(true) >= $deadline) {
                        proc_terminate($process, 9);
                        throw new MediaException('Извлечение кадра заняло слишком много времени.');
                    }
                    usleep(100_000);
                }
            } finally {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            if ($exit !== 0 || !is_file($target) || filesize($target) === 0) {
                throw new MediaException('Не удалось извлечь кадр. Выберите другой момент видео.');
            }
            return $this->media->upload($context, $target, 'Обложка ' . $video->publicId . '.jpg');
        } catch (StorageException) {
            throw new MediaException('Не удалось прочитать видео. Попробуйте позже.');
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }
}
