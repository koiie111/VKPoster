<?php

declare(strict_types=1);

namespace App\Integrations\Payments\YooKassa;

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
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Support\IpRange;
use App\Support\Money;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * YooKassa (API v3). The payment is created with `save_payment_method`, the customer pays on YooKassa's page and is sent back; the
 * result arrives as a notification and is always re-read with `GET /payments/{id}` (the notification has no signature, so it is only a
 * hint plus a sender-address check). Renewals charge the saved `payment_method_id`. Every payment carries a 54-FZ receipt.
 * Our own client over `HttpClientInterface` instead of the official SDK: the SDK talks to cURL directly, which tests could not intercept (ADR 0008).
 */
final class YooKassaGateway implements PaymentGateway
{
    /** Addresses YooKassa sends notifications from (https://yookassa.ru/developers/using-api/webhooks). */
    public const NOTIFICATION_RANGES = [
        '185.71.76.0/27',
        '185.71.77.0/27',
        '77.75.153.0/25',
        '77.75.156.11',
        '77.75.156.35',
        '77.75.154.128/25',
        '2a02:5180::/32',
    ];

    private const TAX_SYSTEMS = ['osn' => 1, 'usn_income' => 2, 'usn_income_outcome' => 3, 'envd' => 4, 'esn' => 5, 'patent' => 6];
    private const VAT_CODES = ['none' => 1, 'vat0' => 2, 'vat10' => 3, 'vat20' => 4, 'vat22' => 11];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $shopId,
        #[SensitiveParameter] private readonly string $secretKey,
        private readonly string $apiBase,
        private readonly bool $verifyIp,
        private readonly string $taxSystem,
        private readonly string $vat,
        private readonly string $itemName,
    ) {
    }

    public function name(): string
    {
        return 'yookassa';
    }

    public function label(): string
    {
        return 'Банковская карта (ЮKassa)';
    }

    public function isConfigured(): bool
    {
        return $this->shopId !== '' && $this->secretKey !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $body = $this->orderBody($invoice, $payment) + [
            'capture' => true,
            'save_payment_method' => $savePaymentMethod,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
        ];

        return $this->parsePayment($this->call('POST', '/payments', $body, $payment->publicId));
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        $body = $this->orderBody($invoice, $payment) + ['capture' => true, 'payment_method_id' => $method->providerMethodId];

        return $this->parsePayment($this->call('POST', '/payments', $body, $payment->publicId));
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        if ($payment->providerPaymentId === null) {
            throw new GatewayException('Refund of a payment that has no provider id.', 'Этот платёж нельзя вернуть.');
        }
        $body = [
            'payment_id' => $payment->providerPaymentId,
            'amount' => ['value' => Money::decimal($amount), 'currency' => $payment->currency],
        ];
        // The receipt of a refund needs the same item, so it is rebuilt from the payment (the customer's email is on the original receipt).
        $data = $this->call('POST', '/refunds', $body, 'refund-' . $payment->publicId . '-' . $payment->refundedAmount . '-' . $amount);
        $refunded = Money::fromDecimal($data['amount']['value'] ?? null) ?? $amount;

        return new GatewayRefund((string) ($data['id'] ?? ''), ($data['status'] ?? '') === 'succeeded', $refunded);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if ($this->verifyIp && !IpRange::containsAny($request->ip(), self::NOTIFICATION_RANGES)) {
            throw new WebhookRejected('YooKassa notification from an unknown address.');
        }
        $data = $request->json();
        $event = $data['event'] ?? null;
        $object = $data['object'] ?? null;
        if (($data['type'] ?? null) !== 'notification' || !is_string($event) || !is_array($object) || !is_string($object['id'] ?? null)) {
            throw new WebhookRejected('YooKassa notification is not readable.');
        }
        // A refund notification names the payment it belongs to.
        $paymentId = str_starts_with($event, 'refund.') ? ($object['payment_id'] ?? null) : $object['id'];
        $amount = is_array($object['amount'] ?? null) ? Money::fromDecimal($object['amount']['value'] ?? null) : null;

        return new WebhookEvent($this->name(), mb_substr($event . ':' . $object['id'], 0, 190), $event, is_string($paymentId) ? $paymentId : null, $amount);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        return $this->parsePayment($this->call('GET', '/payments/' . rawurlencode($providerPaymentId)));
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderBody(Invoice $invoice, Payment $payment): array
    {
        $amount = ['value' => Money::decimal($invoice->amount), 'currency' => $invoice->currency];

        $body = [
            'amount' => $amount,
            'description' => mb_substr($invoice->description, 0, 128),
            'metadata' => ['payment' => $payment->publicId, 'invoice' => $invoice->number],
            'receipt' => [
                'customer' => ['email' => $invoice->customerEmail],
                'tax_system_code' => self::TAX_SYSTEMS[$this->taxSystem] ?? self::TAX_SYSTEMS['usn_income'],
                'items' => [[
                    'description' => mb_substr($this->itemName . ': ' . $invoice->description, 0, 128),
                    'quantity' => '1.00',
                    'amount' => $amount,
                    'vat_code' => self::VAT_CODES[$this->vat] ?? self::VAT_CODES['none'],
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'service',
                ]],
            ],
        ];
        if ($this->taxSystem === 'npd') {
            // A self-employed seller issues receipts in "Мой налог"; there is no cash register receipt to send.
            unset($body['receipt']);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parsePayment(array $data): GatewayPayment
    {
        $id = $data['id'] ?? null;
        $providerStatus = $data['status'] ?? null;
        if (!is_string($id) || !is_string($providerStatus)) {
            throw new GatewayException('YooKassa answered without a payment id or status.', retryable: true);
        }
        $amount = is_array($data['amount'] ?? null) ? Money::fromDecimal($data['amount']['value'] ?? null) : null;
        if ($amount === null) {
            throw new GatewayException('YooKassa answered without a readable amount.', retryable: true);
        }
        $status = match ($providerStatus) {
            'succeeded' => PaymentStatus::Succeeded,
            'canceled' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
        $confirmation = is_array($data['confirmation'] ?? null) && is_string($data['confirmation']['confirmation_url'] ?? null) ? $data['confirmation']['confirmation_url'] : null;
        $reason = is_array($data['cancellation_details'] ?? null) && is_string($data['cancellation_details']['reason'] ?? null) ? $data['cancellation_details']['reason'] : null;

        return new GatewayPayment($id, $status, $providerStatus, $amount, (string) ($data['amount']['currency'] ?? 'RUB'), $confirmation, $this->method($data), $reason);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function method(array $data): ?GatewayMethod
    {
        $method = $data['payment_method'] ?? null;
        if (!is_array($method) || ($method['saved'] ?? false) !== true || !is_string($method['id'] ?? null)) {
            return null;
        }
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];
        $title = is_string($card['last4'] ?? null)
            ? (is_string($card['card_type'] ?? null) ? $card['card_type'] : 'Карта') . ' •• ' . $card['last4']
            : (is_string($method['title'] ?? null) ? $method['title'] : 'Сохранённый способ оплаты');

        return new GatewayMethod($method['id'], $title);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function call(string $method, string $path, ?array $body = null, ?string $idempotenceKey = null): array
    {
        $options = ['auth' => [$this->shopId, $this->secretKey], 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false, 'headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($idempotenceKey !== null) {
            $options['headers']['Idempotence-Key'] = $idempotenceKey;
        }
        try {
            $response = $this->http->request($method, $this->apiBase . $path, $options);
        } catch (GuzzleException) {
            throw new GatewayException('YooKassa: transport error.', retryable: true);
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];
        if ($status >= 500 || $status === 429) {
            throw new GatewayException(sprintf('YooKassa: HTTP %d.', $status), retryable: true);
        }
        if ($status === 401 || $status === 403) {
            throw new GatewayException(sprintf('YooKassa refused the credentials (HTTP %d).', $status), 'Приём платежей сейчас настраивается. Попробуйте позже или напишите нам.');
        }
        if ($status >= 400) {
            $code = is_string($data['code'] ?? null) ? $data['code'] : 'unknown';
            $parameter = is_string($data['parameter'] ?? null) ? ' (' . $data['parameter'] . ')' : '';

            throw new GatewayException(sprintf('YooKassa rejected the request: HTTP %d, %s%s.', $status, $code, $parameter));
        }

        return $data;
    }
}
