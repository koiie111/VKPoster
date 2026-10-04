<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * State of one payment attempt, in our terms (every provider's statuses are mapped to these in its gateway).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В обработке',
            self::Succeeded => 'Оплачен',
            self::Failed => 'Не прошёл',
            self::Refunded => 'Возвращён',
        };
    }
}
