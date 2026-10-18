<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Crypto;

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
use App\Support\Money;
use SensitiveParameter;

/**
 * Cryptocurrency through Heleket (ADR 0015 compares the candidates). One invoice per period, no automatic renewal: the customer pays
 * the requested amount in a coin of their choice on the provider's page. Every request and notification is signed with
 * `md5(base64(body) . apiKey)`. `paid` and `paid_over` count as paid (an overpayment is the provider's to return); `wrong_amount`
 * (underpayment) stays pending, so the invoice is not settled and the case is handled by hand in the provider's cabinet and in the
 * admin area. Refunds are made in the provider's cabinet.
 */
final class CryptoGateway implements PaymentGateway
{
    private const API = 'https://api.heleket.com/v1';

    private readonly GatewayHttp $api;

    /**
     * @param list<string> $currencies
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $merchantId,
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $callbackUrl,
        private readonly array $currencies = ['USD'],
        private readonly string $apiBase = self::API,
    ) {
        $this->api = new GatewayHttp($http, 'Heleket');
    }

    public function name(): string
    {
        return 'crypto';
    }

    public function label(): string
    {
        return 'Криптовалюта';
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
        return $this->merchantId !== '' && $this->apiKey !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $data = $this->call('/payment', [
            'amount' => Money::decimal($invoice->amount),
            'currency' => $invoice->currency,
            'order_id' => $payment->publicId,
            'url_return' => $returnUrl,
            'url_success' => $returnUrl,
            'url_callback' => $this->callbackUrl,
            'lifetime' => 3600,
            'is_payment_multiple' => false,
        ]);

        return $this->payment($data['result'] ?? [], $invoice->amount);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        throw new GatewayException('Cryptocurrency has no saved methods.', 'Этот способ оплаты не поддерживает автопродление.');
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        throw new GatewayException('Crypto refunds are made in the provider cabinet.', 'Возврат криптоплатежа делается вручную в кабинете провайдера.');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = json_decode($request->rawBody, true);
        $data = is_array($data) ? $data : [];
        $given = $data['sign'] ?? null;
        unset($data['sign']);
        $expected = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . $this->apiKey);
        if ($this->apiKey === '' || !is_string($given) || !hash_equals($expected, $given)) {
            throw new WebhookRejected('Heleket notification has a wrong signature.');
        }
        $uuid = $data['uuid'] ?? null;
        $status = $data['status'] ?? $data['payment_status'] ?? null;
        if (!is_string($uuid) || !is_string($status)) {
            throw new WebhookRejected('Heleket notification is not readable.');
        }

        return new WebhookEvent($this->name(), $uuid . ':' . $status, $status, $uuid);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $data = $this->call('/payment/info', ['uuid' => $providerPaymentId]);

        return $this->payment($data['result'] ?? [], 0);
    }

    public function webhookAck(): Response
    {
        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function payment(array $result, int $fallbackAmount): GatewayPayment
    {
        $uuid = $result['uuid'] ?? null;
        $providerStatus = $result['payment_status'] ?? $result['status'] ?? null;
        if (!is_string($uuid) || !is_string($providerStatus)) {
            throw new GatewayException('Heleket answered without a payment id or status.', retryable: true);
        }
        $status = match ($providerStatus) {
            'paid', 'paid_over' => PaymentStatus::Succeeded,
            'fail', 'cancel', 'system_fail' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
        // `amount` is what we asked for; the money that really arrived is in `payment_amount` and is the provider's business to reconcile.
        $amount = is_string($result['amount'] ?? null) ? Money::fromDecimal($result['amount']) : null;

        return new GatewayPayment(
            $uuid,
            $status,
            $providerStatus,
            $amount ?? $fallbackAmount,
            (string) ($result['currency'] ?? 'USD'),
            is_string($result['url'] ?? null) ? $result['url'] : null,
            null,
            $status === PaymentStatus::Failed ? $providerStatus : null,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function call(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        [$status, $data] = $this->api->send('POST', $this->apiBase . $path, [
            'body' => $body,
            'headers' => ['merchant' => $this->merchantId, 'sign' => md5(base64_encode($body) . $this->apiKey), 'Content-Type' => 'application/json'],
        ]);
        if ($status >= 400 || ($data['state'] ?? 0) !== 0) {
            throw new GatewayException(sprintf('Heleket %s failed: HTTP %d, %s.', $path, $status, is_string($data['message'] ?? null) ? $data['message'] : 'no message'));
        }

        return $data;
    }
}
