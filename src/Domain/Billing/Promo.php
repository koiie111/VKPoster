<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * A promo code as the owner set it up. `kind`: `percent` (value 1..100), `amount` (value in minor units of `currency`) or `days` (value =
 * free days of the single plan in `planCodes`, no payment). `planCodes` empty = any plan.
 */
final class Promo
{
    public const KINDS = ['percent' => 'Скидка в процентах', 'amount' => 'Скидка суммой', 'days' => 'Бесплатные дни'];

    /**
     * @param list<string> $planCodes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $kind,
        public readonly int $value,
        public readonly ?string $currency,
        public readonly array $planCodes,
        public readonly bool $firstPaymentOnly,
        public readonly ?int $maxRedemptions,
        public readonly ?DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly bool $active,
        public readonly string $note,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
