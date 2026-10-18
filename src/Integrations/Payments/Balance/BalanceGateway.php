<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Balance;

use App\Domain\Billing\Invoice;
use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\GatewayPayment;
use App\Integrations\Payments\Contracts\GatewayRefund;
use App\Integrations\Payments\Contracts\PaymentGateway;
use App\Integrations\Payments\Contracts\WebhookEvent;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Kernel\Database\Connection;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Paying with the referral balance: no outside provider. `BillingService` takes the money from the balance first and marks the payment
 * paid (`provider_status = paid`), then the usual status check reads that mark, so the plan is applied by the same code as for any
 * other provider. Never offered in the general list of providers (the payer's balance decides), never charged later.
 */
final class BalanceGateway implements PaymentGateway
{
    public const NAME = 'balance';
    public const PREFIX = 'balance_';

    public function __construct(private readonly Connection $db)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Реферальный баланс';
    }

    public function currencies(): array
    {
        return ['RUB'];
    }

    public function supportsRecurring(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        return new GatewayPayment(self::PREFIX . $payment->publicId, PaymentStatus::Pending, 'created', $invoice->amount, 'RUB', null);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        throw new GatewayException('The balance has no saved methods.', 'Этот способ оплаты не поддерживает автопродление.');
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        throw new GatewayException('Payments from the balance are refunded by hand.', 'Платёж с баланса возвращается вручную.');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new WebhookRejected('The balance sends no notifications.');
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $row = str_starts_with($providerPaymentId, self::PREFIX)
            ? $this->db->table('payments')->where('public_id', '=', substr($providerPaymentId, strlen(self::PREFIX)))->where('provider', '=', $this->name())->first()
            : null;
        if ($row === null) {
            throw new GatewayException('Unknown balance payment.', 'Платёж не найден.');
        }
        $raw = (string) ($row['provider_status'] ?? 'created');

        return new GatewayPayment($providerPaymentId, $raw === 'paid' ? PaymentStatus::Succeeded : PaymentStatus::Pending, $raw, (int) $row['amount'], 'RUB');
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }
}
