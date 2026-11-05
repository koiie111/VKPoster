<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Post\PostKind;

/**
 * Checks a library item against what a social network accepts (`config/media.php` → `platforms`). Returns
 * messages for the user; an empty list means the file is fine as it is. Variants (stage 07) can often fix
 * the problems this reports (crop, shrink).
 */
final class PlatformRequirements
{
    private const NAMES = ['telegram' => 'Telegram', 'vk' => 'ВКонтакте', 'max' => 'MAX', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube Shorts'];

    public function __construct(private readonly MediaLimits $limits)
    {
    }

    /**
     * @param bool $includeFixable false leaves out what a rendition fixes by itself (a picture that is too heavy or too large is shrunk
     *                             when the post goes out), so the editor only warns about real obstacles
     * @return list<string>
     */
    public function problems(Media $media, string $platform, bool $includeFixable = true, PostKind $kind = PostKind::Regular): array
    {
        $rules = $this->limits->platforms[$platform] ?? null;
        if ($rules === null) {
            return [];
        }
        $name = self::NAMES[$platform] ?? $platform;
        $problems = [];
        $mb = static fn (int $bytes): string => (string) round($bytes / 1048576, 1);
        $int = static fn (mixed $v): ?int => is_int($v) ? $v : null;

        switch ($media->kind) {
            case MediaKind::Image:
                $max = $int($rules['image_bytes'] ?? null);
                if ($includeFixable && $max !== null && $media->size > $max) {
                    $problems[] = sprintf('%s: фото больше %s МБ.', $name, $mb($max));
                }
                $sum = $int($rules['image_side_sum'] ?? null);
                if ($includeFixable && $sum !== null && ($media->width ?? 0) + ($media->height ?? 0) > $sum) {
                    $problems[] = sprintf('%s: сумма сторон фото больше %d пикселей.', $name, $sum);
                }
                $range = $rules['image_ratio'] ?? null;
                $ratio = $media->ratio();
                // Platforms that take their pictures as public links get the nearest allowed crop when the post goes out (`image_crop`).
                $fixable = ($rules['image_crop'] ?? false) === true;
                if (($includeFixable || !$fixable) && is_array($range) && $ratio !== null && count($range) === 2 && ($ratio < (float) $range[0] - 0.001 || $ratio > (float) $range[1] + 0.001)) {
                    $problems[] = sprintf('%s: соотношение сторон фото должно быть от %s до %s.', $name, self::ratioText((float) $range[0]), self::ratioText((float) $range[1]));
                }
                break;
            case MediaKind::Video:
                if ($kind === PostKind::ShortVideo) {
                    $ratio = $media->ratio();
                    $range = $rules['short_video_ratio'] ?? null;
                    if (is_array($range) && count($range) === 2 && $ratio !== null && ($ratio < (float) $range[0] - 0.001 || $ratio > (float) $range[1] + 0.001)) {
                        $problems[] = sprintf('%s: короткое видео должно быть вертикальным (9:16 или 4:5).', $name);
                    }
                    $minSide = $int($rules['short_video_min_side'] ?? null);
                    if ($minSide !== null && min($media->width ?? 0, $media->height ?? 0) < $minSide) {
                        $problems[] = sprintf('%s: разрешение короткого видео слишком низкое (от %d пикселей по короткой стороне).', $name, $minSide);
                    }
                }
                $max = $int($rules[$kind === PostKind::ShortVideo ? 'short_video_bytes' : 'video_bytes'] ?? null)
                    ?? $int($rules['video_bytes'] ?? null);
                if ($max !== null && $media->size > $max) {
                    $problems[] = sprintf('%s: видео больше %s МБ.', $name, $mb($max));
                }
                $seconds = $int($rules[$kind === PostKind::ShortVideo ? 'short_video_seconds' : 'video_seconds'] ?? null)
                    ?? $int($rules['video_seconds'] ?? null);
                if ($seconds !== null && ($media->durationMs ?? 0) > $seconds * 1000) {
                    $problems[] = sprintf('%s: видео длиннее %d секунд.', $name, $seconds);
                }
                $codecs = $rules['video_codecs'] ?? null;
                if (is_array($codecs) && $media->codec !== null && !in_array($media->codec, $codecs, true) && ($includeFixable || $kind !== PostKind::ShortVideo)) {
                    $problems[] = sprintf('%s: видеокодек %s не поддерживается, нужен %s.', $name, $media->codec, implode(' или ', array_map('strval', $codecs)));
                }
                if ($kind === PostKind::ShortVideo && $includeFixable) {
                    $maxFps = $int($rules['short_video_fps_max'] ?? null);
                    if ($maxFps !== null && $media->frameRate !== null && $media->frameRate > $maxFps) {
                        $problems[] = sprintf('%s: частота кадров должна быть не выше %d кадр/с.', $name, $maxFps);
                    }
                    $audioCodecs = $rules['video_audio_codecs'] ?? null;
                    if (is_array($audioCodecs) && $media->audioCodec !== null && !in_array($media->audioCodec, $audioCodecs, true)) {
                        $problems[] = sprintf('%s: аудиокодек %s будет преобразован в AAC.', $name, $media->audioCodec);
                    }
                }
                break;
            case MediaKind::Document:
                if (($rules['no_documents'] ?? false) === true) {
                    $problems[] = sprintf('%s не принимает документы.', $name);
                    break;
                }
                $max = $int($rules['document_bytes'] ?? null);
                if ($max !== null && $media->size > $max) {
                    $problems[] = sprintf('%s: документ больше %s МБ.', $name, $mb($max));
                }
                break;
        }

        return $problems;
    }

    private static function ratioText(float $ratio): string
    {
        return $ratio >= 1 ? rtrim(rtrim(number_format($ratio, 2, '.', ''), '0'), '.') . ':1' : '4:5';
    }
}
