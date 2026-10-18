<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Paddle;

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
use App\Integrations\Payments\GatewayHttp;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use SensitiveParameter;

/**
 * Paddle Billing. Paddle is the seller (Merchant of Record) and handles taxes, so the price we show is what the customer pays
 * (`tax_mode=internal`). A transaction is created with a one-off price, the customer pays on Paddle's checkout (the "default payment link"
 * must be set in the Paddle dashboard) and the payment is re-read from `/transactions/{id}`. Notifications are trusted only with a valid
 * `Paddle-Signature` (HMAC-SHA256 of `timestamp:body`, at most five minutes old). Renewals are by hand: Paddle cannot charge a stored
 * card from our schedule without its own subscription objects (ADR 0015).
 */
final class PaddleGateway implements PaymentGateway
{
    private const LIVE = 'https://api.paddle.com';
    private const SANDBOX = 'https://sandbox-api.paddle.com';
    private const TOLERANCE_SECONDS = 300;

    private readonly GatewayHttp $api;

    /**
     * @param list<string> $currencies
     */
    public function __construct(
        HttpClientInterface $http,
        #[SensitiveParameter] private readonly string $apiKey,
        #[SensitiveParameter] private readonly string $webhookSecret,
        private readonly Clock $clock,
        private readonly bool $sandbox = false,
        private readonly array $currencies = ['USD'],
        private readonly ?string $apiBase = null,
    ) {
        $this->api = new GatewayHttp($http, 'Paddle');
    }

    public function name(): string
    {
        return 'paddle';
    }

    public function label(): string
    {
        return 'Карта зарубежного банка (Paddle)';
    }

    public function currencies(): array
    {
        return $this->currencies;
    }

    public function supportsRecurring(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->webhookSecret !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $body = [
            'items' => [[
                'quantity' => 1,
                'price' => [
                    'description' => mb_substr($invoice->description, 0, 200),
                    'name' => mb_substr($invoice->description, 0, 100),
                    'tax_mode' => 'internal',
                    'unit_price' => ['amount' => (string) $invoice->amount, 'currency_code' => $invoice->currency],
                    'product' => ['name' => 'ezposter', 'tax_category' => 'saas', 'description' => mb_substr($invoice->description, 0, 200)],
                ],
            ]],
            'collection_mode' => 'automatic',
            'custom_data' => ['payment' => $payment->publicId, 'invoice' => $invoice->number],
        ];
        $data = $this->call('POST', '/transactions', $body);

        return $this->transaction($data['data'] ?? []);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        throw new GatewayException('Paddle does not charge saved methods from our schedule.', 'Этот способ оплаты не поддерживает автопродление.');
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        $id = $payment->providerPaymentId ?? throw new GatewayException('Refund of a payment that has no provider id.', 'Этот платёж нельзя вернуть.');
        $items = [];
        if ($amount >= $payment->amount - $payment->refundedAmount && $payment->refundedAmount === 0) {
            $type = 'full';
        } else {
            $transaction = $this->call('GET', '/transactions/' . rawurlencode($id));
            $line = $transaction['data']['details']['line_items'][0]['id'] ?? null;
            if (!is_string($line)) {
                throw new GatewayException('Paddle: no line item to refund.', 'Этот платёж нельзя вернуть.');
            }
            $type = 'partial';
            $items = [['type' => 'partial', 'item_id' => $line, 'amount' => (string) $amount]];
        }
        $body = ['action' => 'refund', 'transaction_id' => $id, 'reason' => 'requested by the service owner', 'type' => $type] + ($items === [] ? [] : ['items' => $items]);
        $data = $this->call('POST', '/adjustments', $body);
        $adjustment = is_array($data['data'] ?? null) ? $data['data'] : [];

        // A refund may wait for Paddle's approval (`pending_approval`): then it is not done yet.
        return new GatewayRefund((string) ($adjustment['id'] ?? ''), ($adjustment['status'] ?? '') === 'approved', $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $header = $request->header('Paddle-Signature') ?? '';
        $timestamp = null;
        $signatures = [];
        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 'ts' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'h1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($this->webhookSecret === '' || $timestamp === null || $signatures === []) {
            throw new WebhookRejected('Paddle notification has no usable signature.');
        }
        if (abs($this->clock->now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new WebhookRejected('Paddle notification is too old.');
        }
        $expected = hash_hmac('sha256', $timestamp . ':' . $request->rawBody, $this->webhookSecret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (!$valid) {
            throw new WebhookRejected('Paddle notification has a wrong signature.');
        }
        $event = json_decode($request->rawBody, true);
        $event = is_array($event) ? $event : [];
        if (!is_string($event['event_id'] ?? null) || !is_string($event['event_type'] ?? null) || !is_array($event['data'] ?? null)) {
            throw new WebhookRejected('Paddle notification is not readable.');
        }
        $type = $event['event_type'];
        // `transaction.*` events carry the transaction itself; an adjustment (refund) names its transaction.
        $id = str_starts_with($type, 'transaction.') ? ($event['data']['id'] ?? null) : ($event['data']['transaction_id'] ?? null);

        return new WebhookEvent($this->name(), mb_substr($event['event_id'], 0, 190), $type, is_string($id) ? $id : null);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $data = $this->call('GET', '/transactions/' . rawurlencode($providerPaymentId));

        return $this->transaction($data['data'] ?? []);
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $data a Paddle transaction
     */
    private function transaction(array $data): GatewayPayment
    {
        $id = $data['id'] ?? null;
        $providerStatus = $data['status'] ?? null;
        $totals = $data['details']['totals'] ?? null;
        $amount = is_array($totals) && is_string($totals['grand_total'] ?? null) && ctype_digit($totals['grand_total']) ? (int) $totals['grand_total'] : null;
        if (!is_string($id) || !is_string($providerStatus) || $amount === null) {
            throw new GatewayException('Paddle answered without a transaction id, status or total.', retryable: true);
        }
        $status = match ($providerStatus) {
            'paid', 'completed' => PaymentStatus::Succeeded,
            'canceled' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
        $url = is_array($data['checkout'] ?? null) && is_string($data['checkout']['url'] ?? null) ? $data['checkout']['url'] : null;

        return new GatewayPayment($id, $status, $providerStatus, $amount, (string) ($data['currency_code'] ?? ''), $url, null, $status === PaymentStatus::Failed ? 'canceled' : null);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']];
        if ($body !== null) {
            $options['json'] = $body;
        }
        [$status, $data] = $this->api->send($method, ($this->apiBase ?? ($this->sandbox ? self::SANDBOX : self::LIVE)) . $path, $options);
        if ($status >= 400) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            throw new GatewayException(sprintf('Paddle rejected the request: HTTP %d, %s.', $status, is_string($error['code'] ?? null) ? $error['code'] : 'unknown'));
        }

        return $data;
    }
}
