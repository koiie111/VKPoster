<?php

declare(strict_types=1);

namespace App\Integrations\Payments\TBank;

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
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * T-Bank internet acquiring (API v2). `Init` opens the payment page (with `Recurrent=Y` the card is kept and its `RebillId` arrives in the
 * notification), `Charge` takes money by `RebillId` for renewals, `GetState` and `Cancel` read and refund. Requests and notifications are
 * signed with the `Token` (see `TBankSigner`); a notification is answered with the plain text `OK`.
 */
final class TBankGateway implements PaymentGateway
{
    private const TAX_SYSTEMS = ['osn', 'usn_income', 'usn_income_outcome', 'patent', 'envd', 'esn'];
    private const VAT = ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat20', 'vat22'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $terminalKey,
        #[SensitiveParameter] private readonly string $password,
        private readonly string $apiBase,
        private readonly string $notificationUrl,
        private readonly string $taxSystem,
        private readonly string $vat,
        private readonly string $itemName,
    ) {
    }

    public function name(): string
    {
        return 'tbank';
    }

    public function label(): string
    {
        return 'Банковская карта (Т-Банк)';
    }

    public function isConfigured(): bool
    {
        return $this->terminalKey !== '' && $this->password !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $params = $this->initParams($invoice, $payment) + ['SuccessURL' => $returnUrl, 'FailURL' => $returnUrl];
        if ($savePaymentMethod) {
            $params['Recurrent'] = 'Y';
        }
        $data = $this->call('Init', $params);

        return new GatewayPayment(
            (string) $data['PaymentId'],
            PaymentStatus::Pending,
            (string) ($data['Status'] ?? 'NEW'),
            $invoice->amount,
            $invoice->currency,
            is_string($data['PaymentURL'] ?? null) ? $data['PaymentURL'] : null,
        );
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        $init = $this->call('Init', $this->initParams($invoice, $payment));
        $paymentId = (string) $init['PaymentId'];
        try {
            $charge = $this->call('Charge', ['PaymentId' => $paymentId, 'RebillId' => $method->providerMethodId], true);
        } catch (GatewayException $e) {
            if (!$e->retryable) {
                throw $e;
            }

            // The answer to the charge was lost, so the money may or may not have moved. The payment id is kept and the status check decides.
            return new GatewayPayment($paymentId, PaymentStatus::Pending, 'CHARGE_UNKNOWN', $invoice->amount, $invoice->currency);
        }

        return $this->payment($charge + ['PaymentId' => $paymentId, 'Amount' => $invoice->amount]);
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        if ($payment->providerPaymentId === null) {
            throw new GatewayException('Refund of a payment that has no provider id.', 'Этот платёж нельзя вернуть.');
        }
        $params = ['PaymentId' => $payment->providerPaymentId];
        if ($amount < $payment->amount) {
            $params['Amount'] = $amount;
        }
        $data = $this->call('Cancel', $params);
        $status = (string) ($data['Status'] ?? '');

        return new GatewayRefund($payment->providerPaymentId . ':' . $amount, in_array($status, ['REFUNDED', 'PARTIAL_REFUNDED', 'REVERSED', 'PARTIAL_REVERSED'], true), $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->json();
        if (!TBankSigner::verify($data, $this->password) || ($data['TerminalKey'] ?? null) !== $this->terminalKey) {
            throw new WebhookRejected('T-Bank notification has a wrong signature.');
        }
        $paymentId = $data['PaymentId'] ?? null;
        $status = $data['Status'] ?? null;
        if ((!is_string($paymentId) && !is_int($paymentId)) || !is_string($status)) {
            throw new WebhookRejected('T-Bank notification is not readable.');
        }
        $method = null;
        if (isset($data['RebillId']) && (is_int($data['RebillId']) || is_string($data['RebillId'])) && (string) $data['RebillId'] !== '') {
            $pan = is_string($data['Pan'] ?? null) ? $data['Pan'] : '';
            $method = new GatewayMethod((string) $data['RebillId'], $pan !== '' ? 'Карта •• ' . substr($pan, -4) : 'Сохранённая карта', is_string($data['CustomerKey'] ?? null) ? $data['CustomerKey'] : null);
        }

        return new WebhookEvent($this->name(), $paymentId . ':' . $status, $status, (string) $paymentId, is_int($data['Amount'] ?? null) ? $data['Amount'] : null, $method);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        return $this->payment($this->call('GetState', ['PaymentId' => $providerPaymentId]));
    }

    public function webhookAck(): Response
    {
        return Response::text('OK');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function payment(array $data): GatewayPayment
    {
        $providerStatus = (string) ($data['Status'] ?? '');
        $status = match ($providerStatus) {
            'CONFIRMED', 'PARTIAL_REFUNDED', 'PARTIAL_REVERSED' => PaymentStatus::Succeeded,
            'REJECTED', 'AUTH_FAIL', 'DEADLINE_EXPIRED', 'CANCELED', 'REVERSED' => PaymentStatus::Failed,
            'REFUNDED' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };
        $amount = $data['Amount'] ?? null;
        if (!isset($data['PaymentId']) || !is_int($amount)) {
            throw new GatewayException('T-Bank answered without a payment id or amount.', retryable: true);
        }

        return new GatewayPayment((string) $data['PaymentId'], $status, $providerStatus, $amount, 'RUB', null, null, $status === PaymentStatus::Failed ? ($providerStatus . (isset($data['ErrorCode']) ? '/' . $data['ErrorCode'] : '')) : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function initParams(Invoice $invoice, Payment $payment): array
    {
        $name = mb_substr($this->itemName . ': ' . $invoice->description, 0, 128);

        $params = [
            'Amount' => $invoice->amount,
            'OrderId' => $payment->publicId,
            'Description' => mb_substr($invoice->description, 0, 140),
            'CustomerKey' => 'ws' . $invoice->workspaceId,
            'NotificationURL' => $this->notificationUrl,
            'Language' => 'ru',
            'DATA' => ['Email' => $invoice->customerEmail],
            'Receipt' => [
                'Email' => $invoice->customerEmail,
                'Taxation' => in_array($this->taxSystem, self::TAX_SYSTEMS, true) ? $this->taxSystem : 'usn_income',
                'Items' => [[
                    'Name' => $name,
                    'Price' => $invoice->amount,
                    'Quantity' => 1,
                    'Amount' => $invoice->amount,
                    'Tax' => in_array($this->vat, self::VAT, true) ? $this->vat : 'none',
                    'PaymentMethod' => 'full_payment',
                    'PaymentObject' => 'service',
                ]],
            ],
        ];
        if ($this->taxSystem === 'npd') {
            unset($params['Receipt']);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> the answer, with `Success` true
     * @throws GatewayException
     */
    private function call(string $method, array $params, bool $allowFailure = false): array
    {
        $params = ['TerminalKey' => $this->terminalKey] + $params;
        $params['Token'] = TBankSigner::token($params, $this->password);
        try {
            $response = $this->http->request('POST', $this->apiBase . '/' . $method, ['json' => $params, 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false]);
        } catch (GuzzleException) {
            throw new GatewayException('T-Bank: transport error.', retryable: true);
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || $status >= 500) {
            throw new GatewayException(sprintf('T-Bank %s: unreadable answer (HTTP %d).', $method, $status), retryable: true);
        }
        if (($data['Success'] ?? false) !== true) {
            // A declined charge is an answer, not a malfunction: the caller reads the status.
            if ($allowFailure && isset($data['Status'])) {
                return $data;
            }
            $code = (string) ($data['ErrorCode'] ?? '?');

            throw new GatewayException(sprintf('T-Bank %s failed: code %s, %s.', $method, $code, is_string($data['Message'] ?? null) ? $data['Message'] : 'no message'), retryable: in_array($code, ['9999', '1'], true));
        }

        return $data;
    }
}
