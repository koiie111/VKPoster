<?php

declare(strict_types=1);

namespace App\Integrations\Payments\CloudPayments;

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
use App\Integrations\Payments\GatewayHttp;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Money;
use SensitiveParameter;

/**
 * CloudPayments. The customer pays on a hosted order page (`/orders/create`, no widget needed); our own payment id is the provider's
 * `InvoiceId`, so notifications and status checks find the payment by it. A notification is trusted only with a valid `Content-HMAC`
 * (HMAC-SHA256 of the body with the API secret) and is then re-read through `/payments/find`. The card token from the `Pay`
 * notification is kept for renewals (`/payments/tokens/charge`). The notification type comes from the URL the owner enters in the
 * cabinet: `…/webhooks/billing/cloudpayments?type=pay` (also `fail`, `refund`, `check`, `recurrent`).
 */
final class CloudPaymentsGateway implements PaymentGateway
{
    private const API = 'https://api.cloudpayments.ru';

    private readonly GatewayHttp $api;

    /**
     * @param list<string> $currencies
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $publicId,
        #[SensitiveParameter] private readonly string $apiSecret,
        private readonly array $currencies = ['RUB'],
        private readonly string $apiBase = self::API,
    ) {
        $this->api = new GatewayHttp($http, 'CloudPayments');
    }

    public function name(): string
    {
        return 'cloudpayments';
    }

    public function label(): string
    {
        return 'Банковская карта (CloudPayments)';
    }

    public function currencies(): array
    {
        return $this->currencies;
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->publicId !== '' && $this->apiSecret !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $body = [
            'Amount' => (float) Money::decimal($invoice->amount),
            'Currency' => $invoice->currency,
            'Description' => mb_substr($invoice->description, 0, 250),
            'Email' => $invoice->customerEmail,
            'RequireConfirmation' => false,
            'SendEmail' => false,
            'InvoiceId' => $payment->publicId,
            'AccountId' => 'ws' . $invoice->workspaceId,
            'SuccessRedirectUrl' => $returnUrl,
            'FailRedirectUrl' => $returnUrl,
            'JsonData' => json_encode(['payment' => $payment->publicId, 'save' => $savePaymentMethod], JSON_THROW_ON_ERROR),
        ];
        $data = $this->call('/orders/create', $body, $payment->publicId);
        $url = is_array($data['Model'] ?? null) && is_string($data['Model']['Url'] ?? null) ? $data['Model']['Url'] : null;

        return new GatewayPayment($payment->publicId, PaymentStatus::Pending, 'created', $invoice->amount, $invoice->currency, $url);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        $body = [
            'Amount' => (float) Money::decimal($invoice->amount),
            'Currency' => $invoice->currency,
            'AccountId' => $method->customerKey ?? 'ws' . $invoice->workspaceId,
            'Token' => $method->providerMethodId,
            'InvoiceId' => $payment->publicId,
            'Description' => mb_substr($invoice->description, 0, 250),
            'Email' => $invoice->customerEmail,
        ];
        try {
            $data = $this->call('/payments/tokens/charge', $body, $payment->publicId, true);
        } catch (GatewayException $e) {
            if (!$e->retryable) {
                throw $e;
            }

            // The answer was lost, so the money may or may not have moved: the status check decides.
            return new GatewayPayment($payment->publicId, PaymentStatus::Pending, 'CHARGE_UNKNOWN', $invoice->amount, $invoice->currency);
        }
        $model = is_array($data['Model'] ?? null) ? $data['Model'] : [];
        if (($data['Success'] ?? false) === true) {
            return new GatewayPayment($payment->publicId, PaymentStatus::Succeeded, 'Completed', $invoice->amount, $invoice->currency);
        }
        // A declined charge (or a card that wants 3-D Secure, which a renewal cannot do) is an answer, not a malfunction.
        $reason = (string) ($model['Reason'] ?? $data['Message'] ?? 'declined');

        return new GatewayPayment($payment->publicId, PaymentStatus::Failed, 'Declined', $invoice->amount, $invoice->currency, null, null, $reason);
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        $transaction = $this->find($payment->providerPaymentId ?? $payment->publicId);
        $id = $transaction['TransactionId'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            throw new GatewayException('CloudPayments: no transaction to refund.', 'Этот платёж нельзя вернуть.');
        }
        $data = $this->call('/payments/refund', ['TransactionId' => $id, 'Amount' => (float) Money::decimal($amount)], 'refund-' . $payment->publicId . '-' . $payment->refundedAmount . '-' . $amount);
        $refundId = is_array($data['Model'] ?? null) ? (string) ($data['Model']['TransactionId'] ?? '') : '';

        return new GatewayRefund($refundId, ($data['Success'] ?? false) === true, $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $given = $request->header('Content-HMAC') ?? $request->header('X-Content-HMAC') ?? '';
        $expected = base64_encode(hash_hmac('sha256', $request->rawBody, $this->apiSecret, true));
        if ($this->apiSecret === '' || $given === '' || !hash_equals($expected, $given)) {
            throw new WebhookRejected('CloudPayments notification has a wrong signature.');
        }
        $data = $request->all();
        $type = $request->query['type'] ?? 'pay';
        $invoiceId = $data['InvoiceId'] ?? null;
        $transaction = $data['TransactionId'] ?? null;
        if (!is_string($type) || preg_match('/^[a-z]{2,12}$/', $type) !== 1 || !is_string($invoiceId) || $invoiceId === '' || (!is_string($transaction) && !is_int($transaction))) {
            throw new WebhookRejected('CloudPayments notification is not readable.');
        }
        $amount = Money::fromDecimal(is_scalar($data['Amount'] ?? null) ? sprintf('%.2f', (float) $data['Amount']) : null);
        $method = null;
        if ($type === 'pay' && is_string($data['Token'] ?? null) && $data['Token'] !== '') {
            $last = is_string($data['CardLastFour'] ?? null) ? $data['CardLastFour'] : '';
            $method = new GatewayMethod($data['Token'], ($last !== '' ? (is_string($data['CardType'] ?? null) ? $data['CardType'] : 'Карта') . ' •• ' . $last : 'Сохранённая карта'), is_string($data['AccountId'] ?? null) ? $data['AccountId'] : null);
        }

        return new WebhookEvent($this->name(), $type . ':' . $transaction, $type, $invoiceId, $amount, $method);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        $model = $this->find($providerPaymentId);
        $providerStatus = (string) ($model['Status'] ?? '');
        $status = match ($providerStatus) {
            'Completed', 'Authorized' => PaymentStatus::Succeeded,
            'Declined', 'Cancelled' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
        $amount = Money::fromDecimal(is_scalar($model['Amount'] ?? null) ? sprintf('%.2f', (float) $model['Amount']) : null);
        if ($amount === null) {
            throw new GatewayException('CloudPayments answered without a readable amount.', retryable: true);
        }
        $method = null;
        if (is_string($model['Token'] ?? null) && $model['Token'] !== '') {
            $last = is_string($model['CardLastFour'] ?? null) ? $model['CardLastFour'] : '';
            $method = new GatewayMethod($model['Token'], $last !== '' ? (is_string($model['CardType'] ?? null) ? $model['CardType'] : 'Карта') . ' •• ' . $last : 'Сохранённая карта', is_string($model['AccountId'] ?? null) ? $model['AccountId'] : null);
        }

        return new GatewayPayment($providerPaymentId, $status, $providerStatus, $amount, (string) ($model['Currency'] ?? 'RUB'), null, $method, $status === PaymentStatus::Failed ? (string) ($model['Reason'] ?? $providerStatus) : null);
    }

    public function webhookAck(): Response
    {
        return Response::json(['code' => 0]);
    }

    /**
     * @return array<string, mixed> the transaction of an invoice
     * @throws GatewayException
     */
    private function find(string $invoiceId): array
    {
        $data = $this->call('/payments/find', ['InvoiceId' => $invoiceId], null, true);
        if (($data['Success'] ?? false) !== true || !is_array($data['Model'] ?? null)) {
            // No transaction yet: the customer has not paid.
            return ['Status' => 'Created', 'Amount' => 0.0, 'Currency' => $this->currencies[0] ?? 'RUB'];
        }

        return $data['Model'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function call(string $path, array $body, ?string $requestId, bool $allowFailure = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($requestId !== null) {
            $headers['X-Request-ID'] = $requestId;
        }
        [$status, $data] = $this->api->send('POST', $this->apiBase . $path, ['auth' => [$this->publicId, $this->apiSecret], 'json' => $body, 'headers' => $headers]);
        if ($status >= 400 || (($data['Success'] ?? false) !== true && !$allowFailure)) {
            throw new GatewayException(sprintf('CloudPayments %s failed: HTTP %d, %s.', $path, $status, is_string($data['Message'] ?? null) ? $data['Message'] : 'no message'));
        }

        return $data;
    }
}
