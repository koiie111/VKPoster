<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * Social networks a channel can belong to. `Fake` exists only outside production (dev and tests).
 */
enum Platform: string
{
    case Telegram = 'telegram';
    case Vk = 'vk';
    case Max = 'max';
    case Instagram = 'instagram';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Vk => 'ВКонтакте',
            self::Max => 'MAX',
            self::Instagram => 'Instagram',
            self::Fake => 'Тестовая сеть',
        };
    }

    /**
     * Icon name for the UI (Lucide).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Telegram => 'send',
            self::Vk => 'share-2',
            self::Max => 'message-circle',
            self::Instagram => 'image',
            self::Fake => 'zap',
        };
    }
}
