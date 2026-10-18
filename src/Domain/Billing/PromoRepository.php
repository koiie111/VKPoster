<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Persistence of `promo_codes` and `promo_redemptions`. A redemption counts while its invoice is paid or still open and not expired (an
 * abandoned checkout gives the use back), and a redemption without an invoice (free days) always counts.
 */
final class PromoRepository
{
    private const COUNTING = "(r.invoice_id IS NULL OR i.status = 'paid' OR (i.status = 'open' AND i.expires_at > ?))";

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function findByCode(string $code): ?Promo
    {
        $row = $this->db->table('promo_codes')->where('code', '=', $code)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function find(int $id): ?Promo
    {
        $row = $this->db->table('promo_codes')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return list<array{promo: Promo, used: int}>
     */
    public function allWithUse(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT * FROM promo_codes ORDER BY id DESC') as $row) {
            $promo = self::hydrate($row);
            $out[] = ['promo' => $promo, 'used' => $this->used($promo->id)];
        }

        return $out;
    }

    public function used(int $promoId): int
    {
        return (int) ($this->db->select(
            'SELECT COUNT(*) AS n FROM promo_redemptions r LEFT JOIN invoices i ON i.id = r.invoice_id WHERE r.promo_id = ? AND ' . self::COUNTING,
            [$promoId, DbTime::format($this->clock->now())],
        )[0]['n'] ?? 0);
    }

    public function usedBy(int $promoId, int $userId): bool
    {
        return (int) ($this->db->select(
            'SELECT COUNT(*) AS n FROM promo_redemptions r LEFT JOIN invoices i ON i.id = r.invoice_id WHERE r.promo_id = ? AND r.user_id = ? AND ' . self::COUNTING,
            [$promoId, $userId, DbTime::format($this->clock->now())],
        )[0]['n'] ?? 0) > 0;
    }

    /**
     * @param list<string> $planCodes
     */
    public function create(string $code, string $kind, int $value, ?string $currency, array $planCodes, bool $firstPaymentOnly, ?int $maxRedemptions, ?DateTimeImmutable $from, ?DateTimeImmutable $until, string $note, ?int $actorId): Promo
    {
        $id = (int) $this->db->table('promo_codes')->insert([
            'code' => $code,
            'kind' => $kind,
            'value' => $value,
            'currency' => $currency,
            'plan_codes_json' => $planCodes === [] ? null : json_encode($planCodes, JSON_THROW_ON_ERROR),
            'first_payment_only' => $firstPaymentOnly ? 1 : 0,
            'max_redemptions' => $maxRedemptions,
            'valid_from' => $from === null ? null : DbTime::format($from),
            'valid_until' => $until === null ? null : DbTime::format($until),
            'active' => 1,
            'note' => mb_substr($note, 0, 200),
            'created_by' => $actorId,
            'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $this->find($id) ?? throw new \RuntimeException('The promo code was not saved.');
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->table('promo_codes')->where('id', '=', $id)->update(['active' => $active ? 1 : 0]);
    }

    public function redeem(Promo $promo, int $workspaceId, int $userId, ?int $invoiceId, int $discount): void
    {
        $this->db->table('promo_redemptions')->insert([
            'promo_id' => $promo->id,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'invoice_id' => $invoiceId,
            'discount' => $discount,
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * Has this workspace ever paid (a paid invoice)? "First payment only" codes check it.
     */
    public function hasPaid(int $workspaceId): bool
    {
        return $this->db->table('invoices')->where('workspace_id', '=', $workspaceId)->where('status', '=', InvoiceStatus::Paid->value)->exists();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Promo
    {
        $plans = json_decode((string) ($row['plan_codes_json'] ?? ''), true);

        return new Promo(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['kind'],
            (int) $row['value'],
            isset($row['currency']) ? (string) $row['currency'] : null,
            is_array($plans) ? array_values(array_filter($plans, 'is_string')) : [],
            (int) $row['first_payment_only'] === 1,
            isset($row['max_redemptions']) ? (int) $row['max_redemptions'] : null,
            DbTime::parse($row['valid_from'] ?? null),
            DbTime::parse($row['valid_until'] ?? null),
            (int) $row['active'] === 1,
            (string) $row['note'],
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
