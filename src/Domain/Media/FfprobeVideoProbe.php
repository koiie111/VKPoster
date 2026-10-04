<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * `VideoProbe` on the ffmpeg command-line tools. Commands are run without a shell (argument arrays), with a
 * timeout, and input is limited to local files (`-protocol_whitelist file`), so a crafted container cannot make
 * ffmpeg open network addresses or other files.
 */
final class FfprobeVideoProbe implements VideoProbe
{
    public function __construct(
        private readonly string $ffprobe = 'ffprobe',
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function probe(string $path): VideoInfo
    {
        [$code, $out] = $this->run([
            $this->ffprobe, '-v', 'error', '-protocol_whitelist', 'file', '-print_format', 'json',
            '-show_format', '-show_streams', $path,
        ]);
        $data = $code === 0 ? json_decode($out, true) : null;
        if (!is_array($data) || !is_array($data['streams'] ?? null)) {
            throw new MediaException('Не удалось прочитать видео. Файл повреждён или формат не поддерживается.');
        }
        $video = null;
        $audio = false;
        foreach ($data['streams'] as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? '') === 'video' && $video === null && ($stream['disposition']['attached_pic'] ?? 0) !== 1) {
                $video = $stream;
            } elseif (($stream['codec_type'] ?? '') === 'audio') {
                $audio = true;
            }
        }
        if ($video === null) {
            throw new MediaException('В файле нет видеодорожки.');
        }
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        if ($width < 1 || $height < 1) {
            throw new MediaException('Не удалось определить размер видео.');
        }
        $rotation = $this->rotation($video);
        if ($rotation === 90 || $rotation === 270) {
            [$width, $height] = [$height, $width];
        }
        $duration = (float) ($data['format']['duration'] ?? $video['duration'] ?? 0);

        return new VideoInfo($width, $height, (int) round($duration * 1000), strtolower((string) ($video['codec_name'] ?? 'unknown')), $audio);
    }

    public function frame(string $path, string $dest, int $box, float $atSeconds): bool
    {
        [$code] = $this->run([
            $this->ffmpeg, '-v', 'error', '-y', '-protocol_whitelist', 'file', '-ss', (string) max(0.0, $atSeconds), '-i', $path,
            '-frames:v', '1', '-vf', sprintf('scale=%1$d:%1$d:force_original_aspect_ratio=decrease', $box), '-f', 'image2', '-c:v', 'mjpeg', $dest,
        ]);

        return $code === 0 && is_file($dest) && filesize($dest) > 0;
    }

    /**
     * @param array<array-key, mixed> $stream
     */
    private function rotation(array $stream): int
    {
        $rotate = $stream['tags']['rotate'] ?? null;
        if (is_numeric($rotate)) {
            return ((int) $rotate % 360 + 360) % 360;
        }
        foreach (is_array($stream['side_data_list'] ?? null) ? $stream['side_data_list'] : [] as $side) {
            if (is_array($side) && isset($side['rotation']) && is_numeric($side['rotation'])) {
                return ((int) $side['rotation'] % 360 + 360) % 360;
            }
        }

        return 0;
    }

    /**
     * @param list<string> $command
     * @return array{int, string} exit code and stdout
     */
    private function run(array $command): array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new MediaException('Обработка видео сейчас недоступна. Попробуйте позже.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $out = '';
        $exitCode = 1;
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $chunk = fread($pipes[1], 65536);
            $out .= is_string($chunk) ? $chunk : '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);
                throw new MediaException('Видео обрабатывается слишком долго. Попробуйте файл поменьше.');
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        // proc_close would report -1 here (the child was already reaped by proc_get_status), so the exit code
        // comes from the status read above.
        proc_close($process);

        return [$exitCode, $out];
    }
}
