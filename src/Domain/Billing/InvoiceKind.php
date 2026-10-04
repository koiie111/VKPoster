<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Why an invoice exists. `New`: a fresh paid period starts now. `Upgrade`: a better plan right away, the paid period stays as it is
 * (the invoice is the prorated difference). `Renewal`: the next period of the same plan (or the one that was chosen for it).
 */
enum InvoiceKind: string
{
    case New = 'new';
    case Upgrade = 'upgrade';
    case Renewal = 'renewal';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Подключение тарифа',
            self::Upgrade => 'Переход на тариф выше',
            self::Renewal => 'Продление',
        };
    }
}
