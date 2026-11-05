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
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Vk => 'ВКонтакте',
            self::Max => 'MAX',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube Shorts',
            self::Fake => 'Тестовая сеть',
        };
    }

    /**
     * Short key of the platform's colour label in the design system (`plat-tg`, ...).
     */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Telegram => 'tg',
            self::Instagram => 'ig',
            self::TikTok => 'tiktok',
            self::YouTube => 'youtube',
            default => $this->value,
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
            self::TikTok => 'clapperboard',
            self::YouTube => 'youtube',
            self::Fake => 'zap',
        };
    }
}
