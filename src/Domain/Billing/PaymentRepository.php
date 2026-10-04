<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Persistence of `payments`: one row per attempt to collect an invoice.
 */
final class PaymentRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(Invoice $invoice, string $provider, bool $saveMethod, ?int $paymentMethodId = null): Payment
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('payments')->insert([
            'public_id' => $publicId,
            'invoice_id' => $invoice->id,
            'workspace_id' => $invoice->workspaceId,
            'provider' => $provider,
            'status' => PaymentStatus::Pending->value,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'payment_method_id' => $paymentMethodId,
            'save_method' => $saveMethod ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByPublicId($publicId) ?? throw new \RuntimeException('The payment was not saved.');
    }

    public function findByPublicId(string $publicId): ?Payment
    {
        if (!Ulid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->table('payments')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findById(int $id): ?Payment
    {
        $row = $this->db->table('payments')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByProviderId(string $provider, string $providerPaymentId): ?Payment
    {
        $row = $this->db->table('payments')->where('provider', '=', $provider)->where('provider_payment_id', '=', $providerPaymentId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function lock(int $id): ?Payment
    {
        $rows = $this->db->table('payments')->where('id', '=', $id)->forUpdate()->get();

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @return list<Payment> newest first
     */
    public function forInvoice(int $invoiceId): array
    {
        return array_map(self::hydrate(...), $this->db->table('payments')->where('invoice_id', '=', $invoiceId)->orderBy('id', 'desc')->get());
    }

    /**
     * Payments the provider has accepted but that are still open after a while: their notification may have been lost, so they are asked about.
     *
     * @return list<Payment>
     */
    public function pendingBetween(\DateTimeImmutable $notAfter, \DateTimeImmutable $notBefore, int $limit = 100): array
    {
        $rows = $this->db->table('payments')->where('status', '=', PaymentStatus::Pending->value)->whereNotNull('provider_payment_id')
            ->where('created_at', '<=', DbTime::format($notAfter))->where('created_at', '>=', DbTime::format($notBefore))->orderBy('id')->limit($limit)->get();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Record what the provider answered when the order was created.
     */
    public function attach(int $id, string $providerPaymentId, ?string $providerStatus, ?string $confirmationUrl): void
    {
        $this->db->table('payments')->where('id', '=', $id)->update([
            'provider_payment_id' => $providerPaymentId,
            'provider_status' => $providerStatus,
            'confirmation_url' => $confirmationUrl === null ? null : mb_substr($confirmationUrl, 0, 1000),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function setProviderStatus(int $id, ?string $providerStatus): void
    {
        $this->db->table('payments')->where('id', '=', $id)->update(['provider_status' => $providerStatus, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function setStatus(int $id, PaymentStatus $status, ?string $providerStatus = null, ?string $error = null, ?int $paymentMethodId = null): void
    {
        $values = ['status' => $status->value, 'updated_at' => DbTime::format($this->clock->now())];
        if ($providerStatus !== null) {
            $values['provider_status'] = $providerStatus;
        }
        if ($error !== null) {
            $values['error_message'] = mb_substr($error, 0, 255);
        }
        if ($paymentMethodId !== null) {
            $values['payment_method_id'] = $paymentMethodId;
        }
        $this->db->table('payments')->where('id', '=', $id)->update($values);
    }

    public function addRefund(int $id, int $amount, bool $full): void
    {
        $this->db->execute(
            'UPDATE payments SET refunded_amount = refunded_amount + ?, status = IF(?, ?, status), updated_at = ? WHERE id = ?',
            [$amount, $full ? 1 : 0, PaymentStatus::Refunded->value, DbTime::format($this->clock->now()), $id],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Payment
    {
        return new Payment(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['invoice_id'],
            (int) $row['workspace_id'],
            (string) $row['provider'],
            isset($row['provider_payment_id']) ? (string) $row['provider_payment_id'] : null,
            PaymentStatus::from((string) $row['status']),
            isset($row['provider_status']) ? (string) $row['provider_status'] : null,
            (int) $row['amount'],
            (string) $row['currency'],
            (int) $row['refunded_amount'],
            isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            (int) $row['save_method'] === 1,
            isset($row['confirmation_url']) ? (string) $row['confirmation_url'] : null,
            isset($row['error_message']) ? (string) $row['error_message'] : null,
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
