<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * An add-on the owner sells next to the plans: more channels, more people, more library space, or AI credits. `quantity` is in the unit of
 * the kind (storage in bytes). Limit add-ons last `days` days from payment and stack with the plan; credits go to the wallet for good.
 * `prices` are minor units by currency.
 */
final class AddonPackage
{
    /** kind => [label, unit shown to people] */
    public const KINDS = [
        'channels' => ['Дополнительные каналы', 'канал.'],
        'members' => ['Дополнительные места в команде', 'мест'],
        'storage_bytes' => ['Дополнительное место в медиатеке', 'МБ'],
        'ai_credits' => ['ИИ-кредиты', 'кредитов'],
    ];

    /**
     * @param array<string, int> $prices
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $quantity,
        public readonly int $days,
        public readonly array $prices,
        public readonly bool $active,
        public readonly int $sort,
    ) {
    }

    public function priceFor(string $currency): ?int
    {
        return $this->prices[$currency] ?? null;
    }

    public function isCredits(): bool
    {
        return $this->kind === 'ai_credits';
    }

    /** What the package gives, in words: "+5 каналов на 30 дней". */
    public function summary(): string
    {
        $amount = $this->kind === 'storage_bytes' ? intdiv($this->quantity, 1024 * 1024) : $this->quantity;

        return '+' . $amount . ' ' . self::KINDS[$this->kind][1] . ($this->isCredits() ? '' : ' на ' . $this->days . ' дн.');
    }
}
