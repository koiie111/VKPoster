<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Stars;

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
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Database\Connection;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use SensitiveParameter;

/**
 * Telegram Stars (currency `XTR`) in the shared bot. The customer opens an invoice link, pays in Telegram and the bot receives
 * `pre_checkout_query` and `successful_payment` (`StarsPayments`), which are the only source of truth: Telegram has no endpoint to ask about
 * a payment, so `fetchStatus()` reads what those updates recorded. Prices in `plan_prices` are stored as stars × 100, like every other currency
 * (display and arithmetic stay the same); the bot is paid `amount / 100` stars. There is no notification URL and no automatic renewal.
 */
final class StarsGateway implements PaymentGateway
{
    public const PREFIX = 'stars_';

    public function __construct(
        private readonly Connection $db,
        private readonly TelegramClientFactory $clients,
        #[SensitiveParameter] private readonly string $botToken,
    ) {
    }

    public function name(): string
    {
        return 'stars';
    }

    public function label(): string
    {
        return 'Telegram Stars';
    }

    public function currencies(): array
    {
        return ['XTR'];
    }

    public function supportsRecurring(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '';
    }

    public static function stars(int $amount): int
    {
        return intdiv($amount, 100);
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $stars = self::stars($invoice->amount);
        if ($stars < 1 || $stars > 10000) {
            throw new GatewayException('Stars: amount out of range.', 'Эту сумму нельзя оплатить звёздами.');
        }
        try {
            $link = $this->clients->make($this->botToken)->call('createInvoiceLink', [
                'title' => mb_substr('ezposter', 0, 32),
                'description' => mb_substr($invoice->description, 0, 255),
                'payload' => $payment->publicId,
                'currency' => 'XTR',
                'prices' => [['label' => mb_substr($invoice->description, 0, 32), 'amount' => $stars]],
            ], mutating: true);
        } catch (PlatformError $e) {
            throw new GatewayException('Stars: createInvoiceLink failed: ' . $e->getMessage(), retryable: true);
        }
        $url = $link['value'] ?? null;

        return new GatewayPayment(self::PREFIX . $payment->publicId, PaymentStatus::Pending, 'created', $invoice->amount, 'XTR', is_string($url) ? $url : null);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        throw new GatewayException('Stars has no saved methods.', 'Этот способ оплаты не поддерживает автопродление.');
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        $row = $this->db->table('payments')->where('id', '=', $payment->id)->first();
        $ref = is_array($row) && is_string($row['provider_ref'] ?? null) ? explode(':', $row['provider_ref'], 2) : [];
        if (count($ref) !== 2 || $amount !== $payment->amount - $payment->refundedAmount || $payment->refundedAmount !== 0) {
            // Telegram refunds a whole payment only.
            throw new GatewayException('Stars: only a full refund of a recorded payment is possible.', 'Звёзды можно вернуть только целиком.');
        }
        try {
            $this->clients->make($this->botToken)->call('refundStarPayment', ['user_id' => (int) $ref[0], 'telegram_payment_charge_id' => $ref[1]], mutating: true);
        } catch (PlatformError $e) {
            throw new GatewayException('Stars: refundStarPayment failed: ' . $e->getMessage(), 'Telegram не принял возврат. Попробуйте позже.');
        }

        return new GatewayRefund($ref[1], true, $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new WebhookRejected('Stars payments arrive as bot updates, not as notifications.');
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $row = str_starts_with($providerPaymentId, self::PREFIX)
            ? $this->db->table('payments')->where('public_id', '=', substr($providerPaymentId, strlen(self::PREFIX)))->where('provider', '=', $this->name())->first()
            : null;
        if ($row === null) {
            throw new GatewayException('Unknown Stars payment.', 'Платёж не найден.');
        }
        $raw = (string) ($row['provider_status'] ?? 'created');

        return new GatewayPayment(
            $providerPaymentId,
            $raw === 'paid' ? PaymentStatus::Succeeded : ($raw === 'cancelled' ? PaymentStatus::Failed : PaymentStatus::Pending),
            $raw,
            (int) $row['amount'],
            'XTR',
        );
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }
}
