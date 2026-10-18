<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Stripe;

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
use App\Support\Clock;
use SensitiveParameter;

/**
 * Stripe over its HTTP API (our own client, like the other providers, instead of the official SDK: tests intercept every call, ADR 0015).
 * The customer pays on a Checkout Session in `payment` mode; asking to keep the card sets `setup_future_usage=off_session`, and renewals
 * are off-session PaymentIntents with the saved card, so the plan renews on our schedule and the rest of billing stays the same as for
 * the Russian providers. A notification is trusted only with a valid `Stripe-Signature` (HMAC-SHA256 of `timestamp.body`, at most five
 * minutes old) and is then re-read from the API. Amounts are in the currency's minor unit (cents), the same as in our records.
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';
    private const TOLERANCE_SECONDS = 300;

    private readonly GatewayHttp $api;

    /**
     * @param list<string> $currencies
     */
    public function __construct(
        HttpClientInterface $http,
        #[SensitiveParameter] private readonly string $secretKey,
        #[SensitiveParameter] private readonly string $webhookSecret,
        private readonly Clock $clock,
        private readonly array $currencies = ['USD', 'EUR'],
        private readonly string $apiBase = self::API,
    ) {
        $this->api = new GatewayHttp($http, 'Stripe');
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Карта зарубежного банка (Stripe)';
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
        return $this->secretKey !== '' && $this->webhookSecret !== '';
    }

    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment
    {
        $form = [
            'mode' => 'payment',
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'client_reference_id' => $payment->publicId,
            'customer_email' => $invoice->customerEmail,
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => strtolower($invoice->currency),
                'unit_amount' => $invoice->amount,
                'product_data' => ['name' => mb_substr($invoice->description, 0, 250)],
            ]]],
            'metadata' => ['payment' => $payment->publicId, 'invoice' => $invoice->number],
            'payment_intent_data' => ['metadata' => ['payment' => $payment->publicId]],
        ];
        if ($savePaymentMethod) {
            $form['customer_creation'] = 'always';
            $form['payment_intent_data']['setup_future_usage'] = 'off_session';
        }
        $data = $this->call('POST', '/checkout/sessions', $form, $payment->publicId);

        return $this->session($data, $invoice->amount, $invoice->currency);
    }

    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment
    {
        $form = [
            'amount' => $invoice->amount,
            'currency' => strtolower($invoice->currency),
            'customer' => $method->customerKey ?? '',
            'payment_method' => $method->providerMethodId,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => mb_substr($invoice->description, 0, 250),
            'receipt_email' => $invoice->customerEmail,
            'metadata' => ['payment' => $payment->publicId, 'invoice' => $invoice->number],
        ];
        try {
            $data = $this->call('POST', '/payment_intents', $form, $payment->publicId, true);
        } catch (GatewayException $e) {
            if (!$e->retryable) {
                throw $e;
            }

            // Idempotency key = payment id: the status check cannot find a charge that never happened, so it stays pending until it expires.
            return new GatewayPayment('pi_unknown_' . $payment->publicId, PaymentStatus::Pending, 'CHARGE_UNKNOWN', $invoice->amount, $invoice->currency);
        }
        $intent = is_array($data['error']['payment_intent'] ?? null) ? $data['error']['payment_intent'] : $data;
        if (!is_string($intent['id'] ?? null)) {
            throw new GatewayException('Stripe answered a charge without a payment intent.', retryable: true);
        }

        return $this->intent($intent);
    }

    public function refund(Payment $payment, int $amount): GatewayRefund
    {
        $id = $payment->providerPaymentId ?? throw new GatewayException('Refund of a payment that has no provider id.', 'Этот платёж нельзя вернуть.');
        $intentId = $id;
        if (str_starts_with($id, 'cs_')) {
            $session = $this->call('GET', '/checkout/sessions/' . rawurlencode($id));
            $intentId = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '';
        }
        if ($intentId === '') {
            throw new GatewayException('Stripe: no payment intent to refund.', 'Этот платёж нельзя вернуть.');
        }
        $data = $this->call('POST', '/refunds', ['payment_intent' => $intentId, 'amount' => $amount], 'refund-' . $payment->publicId . '-' . $payment->refundedAmount . '-' . $amount);

        return new GatewayRefund((string) ($data['id'] ?? ''), in_array($data['status'] ?? '', ['succeeded', 'pending'], true), $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $header = $request->header('Stripe-Signature') ?? '';
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($this->webhookSecret === '' || $timestamp === null || $signatures === []) {
            throw new WebhookRejected('Stripe notification has no usable signature.');
        }
        if (abs($this->clock->now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new WebhookRejected('Stripe notification is too old.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $request->rawBody, $this->webhookSecret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (!$valid) {
            throw new WebhookRejected('Stripe notification has a wrong signature.');
        }
        $event = json_decode($request->rawBody, true);
        $event = is_array($event) ? $event : [];
        $object = $event['data']['object'] ?? null;
        if (!is_string($event['id'] ?? null) || !is_string($event['type'] ?? null) || !is_array($object)) {
            throw new WebhookRejected('Stripe notification is not readable.');
        }
        $id = is_string($object['id'] ?? null) ? $object['id'] : null;
        // Only sessions and payment intents are payments of ours; a charge or a refund event names its intent.
        if (!str_starts_with((string) $id, 'cs_') && !str_starts_with((string) $id, 'pi_')) {
            $id = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;
        }
        $amount = str_starts_with($event['type'], 'checkout.session.') ? ($object['amount_total'] ?? null) : ($object['amount'] ?? null);

        return new WebhookEvent($this->name(), mb_substr($event['id'], 0, 190), $event['type'], $id, is_int($amount) && !str_contains($event['type'], 'refund') ? $amount : null);
    }

    public function fetchStatus(string $providerPaymentId): GatewayPayment
    {
        if (str_starts_with($providerPaymentId, 'cs_')) {
            $data = $this->call('GET', '/checkout/sessions/' . rawurlencode($providerPaymentId) . '?expand[]=payment_intent.payment_method');

            return $this->session($data, 0, 'usd');
        }

        return $this->intent($this->call('GET', '/payment_intents/' . rawurlencode($providerPaymentId) . '?expand[]=payment_method'));
    }

    public function webhookAck(): Response
    {
        return Response::json(['received' => true]);
    }

    /**
     * @param array<string, mixed> $data a Checkout Session
     */
    private function session(array $data, int $fallbackAmount, string $fallbackCurrency): GatewayPayment
    {
        $id = $data['id'] ?? null;
        if (!is_string($id)) {
            throw new GatewayException('Stripe answered without a session id.', retryable: true);
        }
        $paymentStatus = (string) ($data['payment_status'] ?? '');
        $sessionStatus = (string) ($data['status'] ?? '');
        $status = $paymentStatus === 'paid' ? PaymentStatus::Succeeded : ($sessionStatus === 'expired' ? PaymentStatus::Failed : PaymentStatus::Pending);
        $intent = is_array($data['payment_intent'] ?? null) ? $data['payment_intent'] : null;

        return new GatewayPayment(
            $id,
            $status,
            $paymentStatus . '/' . $sessionStatus,
            is_int($data['amount_total'] ?? null) ? $data['amount_total'] : $fallbackAmount,
            strtoupper(is_string($data['currency'] ?? null) ? $data['currency'] : $fallbackCurrency),
            is_string($data['url'] ?? null) ? $data['url'] : null,
            $intent === null ? null : $this->method($intent, is_string($data['customer'] ?? null) ? $data['customer'] : null),
            $status === PaymentStatus::Failed ? 'checkout_expired' : null,
        );
    }

    /**
     * @param array<string, mixed> $data a PaymentIntent
     */
    private function intent(array $data): GatewayPayment
    {
        $id = $data['id'] ?? null;
        $providerStatus = $data['status'] ?? null;
        if (!is_string($id) || !is_string($providerStatus) || !is_int($data['amount'] ?? null)) {
            throw new GatewayException('Stripe answered without a payment intent id, status or amount.', retryable: true);
        }
        // `requires_action` (3-D Secure) cannot be completed without the customer, so a renewal counts it as declined.
        $status = match ($providerStatus) {
            'succeeded' => PaymentStatus::Succeeded,
            'canceled', 'requires_payment_method', 'requires_action' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
        $error = is_array($data['last_payment_error'] ?? null) && is_string($data['last_payment_error']['code'] ?? null) ? $data['last_payment_error']['code'] : $providerStatus;

        return new GatewayPayment($id, $status, $providerStatus, $data['amount'], strtoupper((string) ($data['currency'] ?? 'usd')), null, $this->method($data, is_string($data['customer'] ?? null) ? $data['customer'] : null), $status === PaymentStatus::Failed ? $error : null);
    }

    /**
     * @param array<string, mixed> $intent
     */
    private function method(array $intent, ?string $customer): ?GatewayMethod
    {
        $method = $intent['payment_method'] ?? null;
        if (!is_array($method) || !is_string($method['id'] ?? null) || $customer === null || ($intent['setup_future_usage'] ?? null) !== 'off_session') {
            return null;
        }
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];
        $title = is_string($card['last4'] ?? null) ? (is_string($card['brand'] ?? null) ? ucfirst($card['brand']) : 'Card') . ' •• ' . $card['last4'] : 'Saved card';

        return new GatewayMethod($method['id'], $title, $customer);
    }

    /**
     * @param array<string, mixed>|null $form
     * @return array<string, mixed>
     * @throws GatewayException
     */
    private function call(string $method, string $path, ?array $form = null, ?string $idempotencyKey = null, bool $allowCardError = false): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secretKey, 'Accept' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $options = ['headers' => $headers];
        if ($form !== null) {
            $options['body'] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        [$status, $data] = $this->api->send($method, $this->apiBase . $path, $options);
        if ($status === 402 && $allowCardError && isset($data['error'])) {
            // A declined off-session charge: the error carries the intent, whose status says why.
            return $data;
        }
        if ($status >= 400) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            throw new GatewayException(sprintf('Stripe rejected the request: HTTP %d, %s.', $status, is_string($error['code'] ?? null) ? $error['code'] : (is_string($error['type'] ?? null) ? $error['type'] : 'unknown')));
        }

        return $data;
    }
}
