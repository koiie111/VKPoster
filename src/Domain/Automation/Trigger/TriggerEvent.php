<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

/**
 * What a trigger waits for.
 */
enum TriggerEvent: string
{
    case Published = 'published';
    case AtTime = 'at_time';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'пост опубликован',
            self::AtTime => 'наступило время',
            self::Failed => 'публикация не удалась',
        };
    }
}
