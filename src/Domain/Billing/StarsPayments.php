<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * The two bot updates of a Telegram Stars payment. `pre_checkout_query` must be answered within ten seconds: it is accepted only for an
 * open payment of ours, still waiting, for exactly the stars we asked for. `successful_payment` records the charge (needed for a refund)
 * and lets `BillingService` apply it like any other provider's report.
 */
final class StarsPayments
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly BillingService $billing,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $query a `PreCheckoutQuery`
     * @return array{ok: bool, error: string|null}
     */
    public function preCheckout(array $query): array
    {
        $payment = $this->match($query['invoice_payload'] ?? null, $query['currency'] ?? null, $query['total_amount'] ?? null);

        return $payment === null ? ['ok' => false, 'error' => 'Счёт не найден или уже не действует. Откройте оплату на сайте ещё раз.'] : ['ok' => true, 'error' => null];
    }

    /**
     * @param array<string, mixed> $paid a `SuccessfulPayment`
     * @param int $userId who paid (needed to refund)
     */
    public function paid(array $paid, int $userId): void
    {
        $payment = $this->match($paid['invoice_payload'] ?? null, $paid['currency'] ?? null, $paid['total_amount'] ?? null, true);
        $charge = $paid['telegram_payment_charge_id'] ?? null;
        if ($payment === null || !is_string($charge) || $charge === '') {
            $this->logger->warning('billing.stars_payment_unmatched', ['payload' => is_string($paid['invoice_payload'] ?? null) ? $paid['invoice_payload'] : '']);

            return;
        }
        $this->payments->setReference($payment->id, 'paid', $userId . ':' . $charge);
        $this->billing->sync($this->payments->findById($payment->id) ?? $payment);
    }

    private function match(mixed $payload, mixed $currency, mixed $total, bool $alreadyPaidOk = false): ?Payment
    {
        if (!is_string($payload) || $currency !== 'XTR' || !is_int($total)) {
            return null;
        }
        $payment = $this->payments->findByPublicId($payload);
        if ($payment === null || $payment->provider !== 'stars' || $total * 100 !== $payment->amount) {
            return null;
        }
        $invoice = $this->invoices->findById($payment->invoiceId);
        if ($invoice === null || ($payment->status !== PaymentStatus::Pending && !$alreadyPaidOk) || ($invoice->status !== InvoiceStatus::Open && !$alreadyPaidOk)) {
            return null;
        }
        if (!$alreadyPaidOk && $invoice->expiresAt < $this->clock->now()) {
            return null;
        }

        return $payment;
    }
}
