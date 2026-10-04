<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Persistence of `invoices`. Like the other billing repositories it takes the workspace id explicitly (webhooks and jobs have no member).
 */
final class InvoiceRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(
        int $workspaceId,
        ?int $subscriptionId,
        int $planId,
        BillingPeriod $period,
        InvoiceKind $kind,
        int $amount,
        int $listPrice,
        string $currency,
        string $description,
        string $customerEmail,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $expiresAt,
    ): Invoice {
        $publicId = (string) new Ulid();
        $this->db->transaction(function (Connection $db) use ($workspaceId, $subscriptionId, $planId, $period, $kind, $amount, $listPrice, $currency, $description, $customerEmail, $periodStart, $periodEnd, $expiresAt, $publicId): void {
            // The number is built from the row id (gap-free per table, readable on a receipt); the public id stands in until then.
            $id = (int) $db->table('invoices')->insert([
                'public_id' => $publicId,
                'number' => $publicId,
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscriptionId,
                'plan_id' => $planId,
                'period' => $period->value,
                'kind' => $kind->value,
                'amount' => $amount,
                'list_price' => $listPrice,
                'currency' => $currency,
                'status' => InvoiceStatus::Open->value,
                'description' => mb_substr($description, 0, 255),
                'customer_email' => $customerEmail,
                'period_start' => DbTime::format($periodStart),
                'period_end' => DbTime::format($periodEnd),
                'created_at' => DbTime::format($this->clock->now()),
                'expires_at' => DbTime::format($expiresAt),
            ]);
            $db->table('invoices')->where('id', '=', $id)->update(['number' => sprintf('EZ-%06d', $id)]);
        });

        return $this->findByPublicId($publicId) ?? throw new \RuntimeException('The invoice was not saved.');
    }

    public function findByPublicId(string $publicId): ?Invoice
    {
        if (!Ulid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->table('invoices')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * An invoice of this workspace only (the workspace comes from the resolved membership, so a foreign id answers null).
     */
    public function findForWorkspace(int $workspaceId, string $publicId): ?Invoice
    {
        $invoice = $this->findByPublicId($publicId);

        return $invoice !== null && $invoice->workspaceId === $workspaceId ? $invoice : null;
    }

    public function findById(int $id): ?Invoice
    {
        $row = $this->db->table('invoices')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function lock(int $id): ?Invoice
    {
        $rows = $this->db->table('invoices')->where('id', '=', $id)->forUpdate()->get();

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Paid invoices and the one waiting for payment, newest first (the history on the billing page).
     *
     * @return list<Invoice>
     */
    public function history(int $workspaceId, int $limit = 50): array
    {
        $rows = $this->db->select(
            'SELECT * FROM invoices WHERE workspace_id = ? AND status IN (?, ?) ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)),
            [$workspaceId, InvoiceStatus::Paid->value, InvoiceStatus::Open->value],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * The latest open invoice of the workspace that has not expired, if any.
     */
    public function latestOpen(int $workspaceId, DateTimeImmutable $now): ?Invoice
    {
        $rows = $this->db->select(
            'SELECT * FROM invoices WHERE workspace_id = ? AND status = ? AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$workspaceId, InvoiceStatus::Open->value, DbTime::format($now)],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function markPaid(int $id, DateTimeImmutable $at): void
    {
        $this->db->table('invoices')->where('id', '=', $id)->update(['status' => InvoiceStatus::Paid->value, 'paid_at' => DbTime::format($at)]);
    }

    /**
     * Cancel the open invoices of a workspace (a new checkout replaces them).
     *
     * @return int how many were cancelled
     */
    public function voidOpen(int $workspaceId): int
    {
        return $this->db->table('invoices')->where('workspace_id', '=', $workspaceId)->where('status', '=', InvoiceStatus::Open->value)->update(['status' => InvoiceStatus::Void->value]);
    }

    /**
     * Cancel invoices nobody paid in time.
     *
     * @return int how many were cancelled
     */
    public function voidExpired(DateTimeImmutable $now): int
    {
        return $this->db->table('invoices')->where('status', '=', InvoiceStatus::Open->value)->where('expires_at', '<=', DbTime::format($now))->update(['status' => InvoiceStatus::Void->value]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Invoice
    {
        return new Invoice(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['number'],
            (int) $row['workspace_id'],
            isset($row['subscription_id']) ? (int) $row['subscription_id'] : null,
            (int) $row['plan_id'],
            BillingPeriod::from((string) $row['period']),
            InvoiceKind::from((string) $row['kind']),
            (int) $row['amount'],
            (int) $row['list_price'],
            (string) $row['currency'],
            InvoiceStatus::from((string) $row['status']),
            (string) $row['description'],
            (string) $row['customer_email'],
            DbTime::parse($row['period_start']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['period_end']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['paid_at'] ?? null),
            DbTime::parse($row['expires_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
