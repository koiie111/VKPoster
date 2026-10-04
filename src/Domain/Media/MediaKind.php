<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * What a library item is, derived from its content (never from the file name).
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Фото',
            self::Video => 'Видео',
            self::Document => 'Документ',
        };
    }
}
