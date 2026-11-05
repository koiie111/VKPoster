<?php

declare(strict_types=1);

namespace App\Domain\Media;

/** Converts a local source to a metadata-free H.264/AAC MP4 in the isolated video worker. */
final class VideoTranscoder
{
    private const TIMEOUT_SECONDS = 5400;

    /** @throws MediaException when ffmpeg cannot make a usable file */
    public function transcode(string $source, string $destination, VideoInfo $info): void
    {
        $filter = $info->width > 1080 || $info->height > 1920
            ? 'scale=1080:1920:force_original_aspect_ratio=decrease:force_divisible_by=2'
            : 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        $args = [
            'ffmpeg', '-v', 'error', '-nostdin', '-y', '-protocol_whitelist', 'file', '-i', $source,
            '-map', '0:v:0', '-map', '0:a:0?', '-vf', $filter,
            '-r', '30', '-c:v', 'libx264', '-preset', 'fast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-map_metadata', '-1', '-map_chapters', '-1',
            '-movflags', '+faststart', '-f', 'mp4', $destination,
        ];
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new MediaException('Не удалось подготовить видео для соцсети. Попробуйте позже.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $exit = 1;
        try {
            while (true) {
                // Discard ffmpeg output; it can contain the private source path.
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new MediaException('Видео обрабатывается слишком долго. Попробуйте файл поменьше.');
                }
                usleep(100_000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if ($exit !== 0 || !is_file($destination) || filesize($destination) === 0) {
            @unlink($destination);
            throw new MediaException('Не удалось перекодировать видео. Попробуйте другой MP4-файл.');
        }
    }
}
