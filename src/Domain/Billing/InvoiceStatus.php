<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * An invoice is `Open` until it is paid, cancelled by us (`Void`: replaced by a newer one or expired) or ends up unpaid.
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ждёт оплаты',
            self::Paid => 'Оплачен',
            self::Void => 'Отменён',
        };
    }
}
