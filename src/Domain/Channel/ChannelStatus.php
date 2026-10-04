<?php

declare(strict_types=1);

namespace App\Domain\Channel;

/**
 * Lifecycle of a channel. Only `Active` channels receive posts; `Error` and `Revoked` mean the connection is broken
 * (rights lost, bot removed) and the owner has to reconnect; `Paused` is the owner's own decision.
 */
enum ChannelStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Подключён',
            self::Paused => 'На паузе',
            self::Error => 'Нужно проверить',
            self::Revoked => 'Нужно переподключить',
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::Error || $this === self::Revoked;
    }
}
