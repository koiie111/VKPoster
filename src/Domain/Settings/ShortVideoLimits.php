<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/** Owner-controlled product ceilings for a short video; a plan and a network may impose lower limits. */
final class ShortVideoLimits
{
    private const DEFAULT_FILE_MIB = 1024;
    private const DEFAULT_DURATION_SECONDS = 180;
    private const MAX_FILE_MIB = 10240;
    private const MAX_DURATION_SECONDS = 3600;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function maxFileMiB(): int
    {
        $value = $this->settings->get('limits.short_video_file_mib');

        return is_int($value) && $value >= 1 && $value <= self::MAX_FILE_MIB ? $value : self::DEFAULT_FILE_MIB;
    }

    public function maxDurationSeconds(): int
    {
        $value = $this->settings->get('limits.short_video_duration_seconds');

        return is_int($value) && $value >= 1 && $value <= self::MAX_DURATION_SECONDS ? $value : self::DEFAULT_DURATION_SECONDS;
    }

    public function maxFileBytes(): int
    {
        return $this->maxFileMiB() * 1024 * 1024;
    }

    public function maxDurationMs(): int
    {
        return $this->maxDurationSeconds() * 1000;
    }

    /** @return list<string> Owner-facing validation errors; no setting is written on failure. */
    public function save(string $fileMiB, string $durationSeconds, ?int $actorId): array
    {
        $errors = [];
        if (!ctype_digit($fileMiB) || strlen($fileMiB) > 5 || (int) $fileMiB < 1 || (int) $fileMiB > self::MAX_FILE_MIB) {
            $errors[] = 'Размер короткого видео — от 1 до 10 240 МиБ.';
        }
        if (!ctype_digit($durationSeconds) || strlen($durationSeconds) > 4 || (int) $durationSeconds < 1 || (int) $durationSeconds > self::MAX_DURATION_SECONDS) {
            $errors[] = 'Длительность короткого видео — от 1 до 3600 секунд.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->settings->set('limits.short_video_file_mib', (int) $fileMiB, $actorId);
        $this->settings->set('limits.short_video_duration_seconds', (int) $durationSeconds, $actorId);

        return [];
    }
}
