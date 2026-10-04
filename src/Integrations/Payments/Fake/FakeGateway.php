<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Fake;

use App\Domain\Billing\Invoice;
use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\GatewayMethod;
use App\Integrations\Payments\Contracts\GatewayPayment;
use App\Integrations\Payments\Contracts\GatewayRefund;
use App\Integrations\Payments\Contracts\PaymentGateway;
use App\Integrations\Payments\Contracts\WebhookEvent;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Kernel\Database\Connection;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * A stand-in payment provider for development and tests (the registry never offers it in production). The "payment page" is
 * `/dev/billing/pay/{id}` with the buttons "pay" and "decline". The provider's side of the story is kept in `payments.provider_status`:
 * `pending` until the page is answered, then `succeeded`, `succeeded:decline` (paid with a card whose later charges fail) or `canceled`.
 * It sends no notifications: the return page and the periodic check read the status, exactly like the real gateways do after a lost notification.
 */
final class FakeGateway implements PaymentGateway
{
    public const PAY_PATH = '/dev/billing/pay/';
    private const METHOD_OK = 'fake-card-ok';
    private const METHOD_DECLINING = 'fake-card-declining';

    public function __construct(private readonly Connection $db)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Тестовая оплата (только для разработки)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        // Only the path and query of our own return address are kept: the page redirects there after the answer.
        $parts = parse_url($returnUrl);
        $back = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return new GatewayPayment(
            'fake_' . $payment->publicId,
            PaymentStatus::Pending,
            'pending',
            $invoice->amount,
            $invoice->currency,
            self::PAY_PATH . $payment->publicId . '?return=' . rawurlencode($back),
        );
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        $declined = str_starts_with($method->providerMethodId, self::METHOD_DECLINING);
        $status = $declined ? 'canceled' : 'succeeded';
        $this->db->table('payments')->where('id', '=', $payment->id)->update(['provider_status' => $status]);

        return new GatewayPayment('fake_' . $payment->publicId, $declined ? PaymentStatus::Failed : PaymentStatus::Succeeded, $status, $invoice->amount, $invoice->currency, null, null, $declined ? 'insufficient_funds' : null);
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        return new GatewayRefund('fake-refund-' . $payment->publicId . '-' . $amount, true, $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new WebhookRejected('The test provider sends no notifications.');
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $row = str_starts_with($providerPaymentId, 'fake_') ? $this->db->table('payments')->where('public_id', '=', substr($providerPaymentId, 5))->where('provider', '=', 'fake')->first() : null;
        if ($row === null) {
            throw new GatewayException('Unknown fake payment.', 'Платёж не найден.');
        }
        $raw = (string) ($row['provider_status'] ?? 'pending');
        $paid = str_starts_with($raw, 'succeeded');
        $method = $paid && (int) $row['save_method'] === 1
            // Every payment "creates" its own card, as a real provider does for a new customer (a method id belongs to one workspace).
            ? new GatewayMethod(($raw === 'succeeded:decline' ? self::METHOD_DECLINING : self::METHOD_OK) . ':' . $row['public_id'], 'Тестовая карта •• 4242')
            : null;

        return new GatewayPayment(
            $providerPaymentId,
            $paid ? PaymentStatus::Succeeded : ($raw === 'canceled' ? PaymentStatus::Failed : PaymentStatus::Pending),
            $raw,
            (int) $row['amount'],
            (string) $row['currency'],
            null,
            $method,
            $raw === 'canceled' ? 'canceled_by_customer' : null,
        );
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * Answer the payment page: pay, pay with a card that will be declined at renewal, or reject.
     *
     * @param string $answer pay | pay_declining | decline
     */
    public function answer(string $paymentPublicId, string $answer): void
    {
        $status = match ($answer) {
            'pay' => 'succeeded',
            'pay_declining' => 'succeeded:decline',
            default => 'canceled',
        };
        $this->db->table('payments')->where('public_id', '=', $paymentPublicId)->where('provider', '=', 'fake')->update(['provider_status' => $status]);
    }
}
