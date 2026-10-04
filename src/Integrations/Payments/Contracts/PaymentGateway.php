<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

use App\Domain\Billing\Invoice;
use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentMethod;
use App\Kernel\Http\Request;

/**
 * One payment provider (YooKassa, T-Bank, later others). The billing code talks to this interface only; every provider has a Fake
 * stand-in for tests and local work, and no test reaches a real API.
 *
 * Providers may answer slowly or fail: methods throw `GatewayException`, whose message is safe to log (never a secret) and whose
 * `forUser()` text is safe to show. The provider's own statuses never leak out: they are mapped to `PaymentStatus` here.
 */
interface PaymentGateway
{
    /** Short stable name stored in `payments.provider`, e.g. `yookassa`. */
    public function name(): string;

    /** What the customer sees on the checkout page, e.g. "Банковская карта (ЮKassa)". */
    public function label(): string;

    /** Credentials are present (a gateway without them is not offered). */
    public function isConfigured(): bool;

    /**
     * Start a payment the customer completes on the provider's page.
     *
     * @param bool $savePaymentMethod ask the provider to keep the card for renewals
     * @throws GatewayException
     */
    public function createPayment(Invoice $invoice, Payment $payment, string $returnUrl, bool $savePaymentMethod): GatewayPayment;

    /**
     * Charge a saved method without the customer present (renewal).
     *
     * @throws GatewayException
     */
    public function chargeSaved(Invoice $invoice, Payment $payment, PaymentMethod $method): GatewayPayment;

    /**
     * Give money back (all or part of the payment).
     *
     * @throws GatewayException
     */
    public function refund(Payment $payment, int $amount): GatewayRefund;

    /**
     * Check that a notification really comes from the provider and read it. Nothing in the body is trusted for what happens next: the
     * caller asks `fetchStatus()` for the truth.
     *
     * @throws WebhookRejected when the call is not authentic
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * What the provider says about a payment right now.
     *
     * @throws GatewayException
     */
    public function fetchStatus(string $providerPaymentId): GatewayPayment;

    /**
     * What to answer the provider after a notification was accepted (T-Bank wants the plain text `OK`).
     */
    public function webhookAck(): \App\Kernel\Http\Response;
}
