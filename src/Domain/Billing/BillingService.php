<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\GatewayMethod;
use App\Integrations\Payments\Contracts\GatewayPayment;
use App\Integrations\Payments\Contracts\PaymentGateway;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\GatewayRegistry;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Taking money: the checkout, applying what a provider reports (notification, return page, periodic check) and refunds. The renewal job
 * lives in `RenewalService`; both share `apply()`, the one place where a payment changes a subscription.
 *
 * Rules that hold everywhere:
 * - the provider is asked for the truth (`fetchStatus`), a notification is only a hint; the amount must equal the invoice;
 * - applying is idempotent: locks are taken in the order payment, invoice, subscription, and a settled invoice is never applied twice;
 * - the provider is never called inside a database transaction.
 */
final class BillingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly GatewayRegistry $gateways,
        private readonly PlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodRepository $methods,
        private readonly WebhookEvents $webhookEvents,
        private readonly Ledger $ledger,
        private readonly PriceCalculator $calculator,
        private readonly SubscriptionService $subscriptionService,
        private readonly BillingMailer $mailer,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---- checkout -------------------------------------------------------------------------------------------------

    /**
     * What choosing this plan and period would do and cost right now.
     *
     * @throws BillingException
     */
    public function quote(int $workspaceId, string $planCode, BillingPeriod $period): Quote
    {
        $target = $this->plans->findByCode($planCode);
        if ($target === null || !$target->isPublic) {
            throw new BillingException('Такого тарифа нет.');
        }
        $subscription = $this->subscriptions->findByWorkspace($workspaceId);
        $current = $subscription === null ? null : $this->plans->find($subscription->planId);

        return $this->calculator->quote($subscription, $current, $target, $period, $this->clock->now(), $this->config->string('billing.currency', 'RUB'));
    }

    /**
     * Start paying for a plan. A change that costs nothing now (a cheaper plan) is booked for the end of the paid period instead.
     *
     * @param bool $keepCard ask the provider to keep the card, so the plan renews by itself
     * @throws BillingException with a message for the customer
     */
    public function checkout(WorkspaceContext $context, User $actor, string $planCode, BillingPeriod $period, string $gatewayName, bool $keepCard): CheckoutResult
    {
        if (!$this->permissions->allows($context->role, 'workspace.billing')) {
            throw new BillingException('Оплатой тарифа занимается владелец пространства.');
        }
        $quote = $this->quote($context->workspaceId, $planCode, $period);
        if (!$quote->immediate) {
            $this->subscriptionService->scheduleChange($context->workspaceId, $quote->plan, $period, $actor->id);

            return CheckoutResult::done('Готово: тариф «' . $quote->plan->name . '» начнётся, когда закончится оплаченный срок. До этого всё работает как раньше.');
        }
        $gateway = $this->gateways->get($gatewayName) ?? throw new BillingException('Этот способ оплаты сейчас недоступен. Выберите другой.');
        $email = (string) $actor->email;
        if ($email === '') {
            throw new BillingException('Добавьте почту в профиле: на неё мы пришлём чек.');
        }

        $subscription = $this->subscriptionService->ensure($context->workspaceId);
        [$invoice, $payment] = $this->db->transaction(function () use ($context, $subscription, $quote, $email, $gateway, $keepCard): array {
            // One open bill at a time: a new choice replaces the earlier, unpaid one.
            $this->invoices->voidOpen($context->workspaceId);
            $invoice = $this->invoices->create(
                $context->workspaceId,
                $subscription->id,
                $quote->plan->id,
                $quote->period,
                $quote->kind,
                $quote->amount,
                $quote->listPrice,
                $quote->currency,
                $quote->description,
                $email,
                $quote->periodStart,
                $quote->periodEnd,
                $this->clock->now()->modify(sprintf('+%d hours', $this->config->int('billing.checkout_ttl_hours', 24))),
            );

            return [$invoice, $this->payments->create($invoice, $gateway->name(), $keepCard)];
        });

        $returnUrl = rtrim($this->config->string('app.url'), '/') . '/w/' . $context->workspacePublicId . '/billing/return?payment=' . $payment->publicId;
        try {
            $created = $gateway->createPayment($invoice, $payment, $returnUrl, $keepCard);
        } catch (GatewayException $e) {
            $this->logger->error('billing.create_payment_failed', ['provider' => $gateway->name(), 'payment' => $payment->publicId, 'error' => $e->getMessage()]);
            $this->payments->setStatus($payment->id, PaymentStatus::Failed, null, 'Платёжная система не приняла заказ.');
            $this->db->table('invoices')->where('id', '=', $invoice->id)->update(['status' => InvoiceStatus::Void->value]);

            throw new BillingException($e->forUser());
        }
        $url = self::safeRedirect($created->confirmationUrl);
        if ($url === null) {
            $this->logger->error('billing.no_confirmation_url', ['provider' => $gateway->name(), 'payment' => $payment->publicId]);
            $this->payments->setStatus($payment->id, PaymentStatus::Failed, null, 'Платёжная система не вернула страницу оплаты.');
            $this->db->table('invoices')->where('id', '=', $invoice->id)->update(['status' => InvoiceStatus::Void->value]);

            throw new BillingException('Платёжная система не открыла страницу оплаты. Попробуйте ещё раз.');
        }
        $this->payments->attach($payment->id, $created->providerPaymentId, $created->providerStatus, $url);
        $this->audit->record('billing.checkout_started', $actor->id, 'invoice', $invoice->publicId, ['plan' => $quote->plan->code, 'period' => $period->value, 'amount' => $invoice->amount, 'provider' => $gateway->name()], $context->workspaceId);

        return CheckoutResult::redirect($url, $payment->publicId);
    }

    // ---- applying what a provider reports -------------------------------------------------------------------------

    /**
     * Ask the provider about a payment and apply the answer.
     *
     * @param GatewayMethod|null $announced a saved card reported by a signed notification
     * @throws GatewayException when the provider cannot be asked
     */
    public function sync(Payment $payment, ?GatewayMethod $announced = null): PaymentOutcome
    {
        if ($payment->providerPaymentId === null) {
            return PaymentOutcome::Pending;
        }
        $gateway = $this->gateways->forWebhook($payment->provider) ?? throw new GatewayException('Provider ' . $payment->provider . ' is not available.');

        return $this->apply($payment, $gateway->fetchStatus($payment->providerPaymentId), $announced);
    }

    /**
     * The one place where a provider's report changes our records.
     */
    public function apply(Payment $payment, GatewayPayment $reported, ?GatewayMethod $announced = null): PaymentOutcome
    {
        return $this->db->transaction(function () use ($payment, $reported, $announced): PaymentOutcome {
            $locked = $this->payments->lock($payment->id);
            $invoice = $locked === null ? null : $this->invoices->lock($locked->invoiceId);
            if ($locked === null || $invoice === null) {
                return PaymentOutcome::Rejected;
            }
            $this->payments->setProviderStatus($locked->id, $reported->providerStatus);

            if ($reported->status === PaymentStatus::Failed) {
                if ($locked->status === PaymentStatus::Pending) {
                    $this->payments->setStatus($locked->id, PaymentStatus::Failed, $reported->providerStatus, $reported->failureReason ?? 'Платёж отклонён.');
                    $this->audit->record('billing.payment_failed', null, 'payment', $locked->publicId, ['reason' => $reported->failureReason ?? ''], $locked->workspaceId);
                }

                return PaymentOutcome::Failed;
            }
            if ($reported->status !== PaymentStatus::Succeeded) {
                return PaymentOutcome::Pending;
            }

            if ($reported->amount !== $invoice->amount || $reported->currency !== $invoice->currency) {
                $this->logger->critical('billing.amount_mismatch', ['payment' => $locked->publicId, 'provider' => $locked->provider, 'expected' => $invoice->amount, 'reported' => $reported->amount]);
                $this->audit->record('billing.amount_mismatch', null, 'payment', $locked->publicId, ['expected' => $invoice->amount, 'reported' => $reported->amount], $locked->workspaceId);

                return PaymentOutcome::Rejected;
            }

            $method = $this->rememberMethod($locked, $reported->method ?? $announced);
            if ($locked->status === PaymentStatus::Succeeded) {
                return PaymentOutcome::AlreadySettled;
            }

            $this->payments->setStatus($locked->id, PaymentStatus::Succeeded, $reported->providerStatus, null, $method?->id);
            $paid = $this->payments->findById($locked->id) ?? $locked;
            $this->ledger->recordPayment($paid);
            if ($invoice->status !== InvoiceStatus::Open) {
                // The money is real and recorded, but the invoice was replaced or is already paid: applying it would double the period.
                $this->logger->critical('billing.payment_for_closed_invoice', ['payment' => $paid->publicId, 'invoice' => $invoice->publicId, 'invoice_status' => $invoice->status->value]);
                $this->audit->record('billing.payment_orphaned', null, 'payment', $paid->publicId, ['invoice' => $invoice->publicId, 'invoice_status' => $invoice->status->value, 'amount' => $paid->amount], $paid->workspaceId);

                return PaymentOutcome::Orphaned;
            }

            $this->settle($invoice, $paid, $method);

            return PaymentOutcome::Settled;
        });
    }

    /**
     * Pay the invoice and move the subscription. Runs inside the transaction of `apply()`, with the payment and the invoice locked.
     */
    private function settle(Invoice $invoice, Payment $payment, ?PaymentMethod $method): void
    {
        $now = $this->clock->now();
        $subscription = $this->subscriptions->lock($invoice->workspaceId) ?? $this->subscriptionService->ensure($invoice->workspaceId);
        $plan = $this->plans->find($invoice->planId) ?? throw new \LogicException('The plan of a paid invoice is gone.');
        $this->invoices->markPaid($invoice->id, $now);

        $values = [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'period' => $invoice->period->value,
            'price_amount' => $invoice->listPrice,
            'current_period_start' => DbTime::format($invoice->kind === InvoiceKind::Upgrade ? ($subscription->currentPeriodStart ?? $invoice->periodStart) : $invoice->periodStart),
            'current_period_end' => DbTime::format($invoice->periodEnd),
            'trial_ends_at' => null,
            'pending_plan_id' => null,
            'pending_period' => null,
            'renewal_attempts' => 0,
            'first_attempt_at' => null,
            'last_failure' => null,
        ];
        if ($invoice->kind === InvoiceKind::New) {
            // Not keeping the card means the customer renews by hand.
            $values['cancel_at_period_end'] = $payment->saveMethod ? 0 : 1;
        }
        $cancel = $invoice->kind === InvoiceKind::New ? !$payment->saveMethod : $subscription->cancelAtPeriodEnd;
        $methodId = $method->id ?? $subscription->paymentMethodId;
        if ($methodId !== null) {
            $values['payment_method_id'] = $methodId;
        }
        $values['next_renewal_attempt_at'] = !$cancel && $methodId !== null ? $this->subscriptionService->firstAttemptAt($invoice->periodEnd) : null;
        $this->subscriptions->update($subscription->id, $values);
        $this->subscriptionService->syncWorkspacePlan($invoice->workspaceId, $plan->id);
        $this->subscriptionService->enforce($invoice->workspaceId);

        $this->audit->record('billing.payment_succeeded', null, 'invoice', $invoice->publicId, ['plan' => $plan->code, 'period' => $invoice->period->value, 'amount' => $invoice->amount, 'kind' => $invoice->kind->value, 'provider' => $payment->provider], $invoice->workspaceId);
        $this->mailer->paymentReceived($invoice, $plan, $invoice->periodEnd);
    }

    private function rememberMethod(Payment $payment, ?GatewayMethod $method): ?PaymentMethod
    {
        if ($method === null || !$payment->saveMethod) {
            return null;
        }

        try {
            return $this->methods->remember($payment->workspaceId, $payment->provider, $method->providerMethodId, $method->title, $method->customerKey);
        } catch (\DomainException) {
            $this->logger->critical('billing.method_of_another_workspace', ['payment' => $payment->publicId]);

            return null;
        }
    }

    /**
     * Take the saved card back (the customer wants to stop automatic charging for good). The plan runs to the end of the paid period.
     */
    public function forgetMethod(WorkspaceContext $context, ?int $actorId): void
    {
        if (!$this->permissions->allows($context->role, 'workspace.billing')) {
            throw new BillingException('Оплатой тарифа занимается владелец пространства.');
        }
        $subscription = $this->subscriptionService->ensure($context->workspaceId);
        if ($subscription->paymentMethodId === null) {
            return;
        }
        $this->methods->revoke($subscription->paymentMethodId);
        $this->subscriptions->update($subscription->id, ['payment_method_id' => null, 'next_renewal_attempt_at' => null]);
        $this->audit->record('billing.method_removed', $actorId, 'subscription', $subscription->publicId, [], $context->workspaceId);
    }

    // ---- notifications --------------------------------------------------------------------------------------------

    /**
     * Handle a provider's notification: check it is genuine, ignore a repeat, ask the provider for the real state and apply it. Answers the
     * way the provider wants (200 for everything we decided about, an error only when we want the call repeated).
     *
     * @throws HttpException 404 for an unknown or switched-off provider, 403 for a call that is not genuine
     */
    public function handleWebhook(string $provider, Request $request): Response
    {
        $gateway = $this->gateways->forWebhook($provider) ?? throw new HttpException(404, 'Not found');
        try {
            $event = $gateway->parseWebhook($request);
        } catch (WebhookRejected $e) {
            $this->logger->warning('billing.webhook_rejected', ['provider' => $provider, 'ip' => $request->ip(), 'reason' => $e->getMessage()]);

            throw new HttpException(403, 'Forbidden');
        }
        if (!$this->webhookEvents->begin($event, $request->rawBody)) {
            return $gateway->webhookAck();
        }

        try {
            $payment = $event->providerPaymentId === null ? null : $this->payments->findByProviderId($provider, $event->providerPaymentId);
            if ($payment === null) {
                $this->webhookEvents->finish($event, 'ignored', 'unknown payment');

                return $gateway->webhookAck();
            }
            if ($event->amount !== null && $event->amount !== $payment->amount && !str_starts_with($event->type, 'refund')) {
                $this->logger->critical('billing.webhook_amount_mismatch', ['provider' => $provider, 'payment' => $payment->publicId, 'expected' => $payment->amount, 'reported' => $event->amount]);
                $this->audit->record('billing.amount_mismatch', null, 'payment', $payment->publicId, ['expected' => $payment->amount, 'reported' => $event->amount, 'source' => 'webhook'], $payment->workspaceId);
                $this->webhookEvents->finish($event, 'rejected', 'amount mismatch');

                return $gateway->webhookAck();
            }
            $outcome = $this->sync($payment, $event->method);
            $this->webhookEvents->finish($event, $outcome === PaymentOutcome::Rejected ? 'rejected' : 'processed', $outcome->value);
        } catch (\Throwable $e) {
            // Let the provider repeat the call: the next delivery must not be taken for a duplicate.
            $this->webhookEvents->forget($event);
            $this->logger->error('billing.webhook_failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            throw $e instanceof HttpException ? $e : new HttpException(503, 'Try again later');
        }

        return $gateway->webhookAck();
    }

    // ---- money back -----------------------------------------------------------------------------------------------

    /**
     * Give money back through the provider and record it. The subscription is left as it is: whether to cut the plan short is a decision
     * for the owner of the service (see the admin area).
     *
     * @throws BillingException
     */
    public function refund(Payment $payment, int $amount, ?int $actorId): void
    {
        $left = $payment->amount - $payment->refundedAmount;
        if ($payment->status !== PaymentStatus::Succeeded || $amount <= 0 || $amount > $left) {
            throw new BillingException('Эту сумму вернуть нельзя.');
        }
        $gateway = $this->gateways->forWebhook($payment->provider) ?? throw new BillingException('Платёжная система недоступна.');
        try {
            $refund = $gateway->refund($payment, $amount);
        } catch (GatewayException $e) {
            $this->logger->error('billing.refund_failed', ['payment' => $payment->publicId, 'error' => $e->getMessage()]);

            throw new BillingException($e->forUser());
        }
        if (!$refund->succeeded) {
            throw new BillingException('Платёжная система приняла возврат, но ещё не выполнила его. Проверьте позже.');
        }
        $this->db->transaction(function () use ($payment, $amount, $refund, $left, $actorId): void {
            $this->ledger->recordRefund($payment, $amount, $refund->refundId);
            $this->payments->addRefund($payment->id, $amount, $amount === $left);
            $this->audit->record('billing.refunded', $actorId, 'payment', $payment->publicId, ['amount' => $amount], $payment->workspaceId);
        });
    }

    // ---- reading --------------------------------------------------------------------------------------------------

    /**
     * Payments waiting at a provider for longer than a few minutes: ask about each (a notification may have got lost).
     *
     * @return int how many were asked about
     */
    public function reconcilePending(): int
    {
        $now = $this->clock->now();
        $checked = 0;
        foreach ($this->payments->pendingBetween($now->modify('-5 minutes'), $now->modify('-3 days')) as $payment) {
            try {
                $this->sync($payment);
                ++$checked;
            } catch (\Throwable $e) {
                $this->logger->warning('billing.reconcile_failed', ['payment' => $payment->publicId, 'error' => $e->getMessage()]);
            }
        }

        return $checked;
    }

    /**
     * A provider's confirmation page is a link to leave our site for: it must be https (a relative path is allowed for the test provider).
     */
    private static function safeRedirect(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_starts_with($url, 'https://') || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return $url;
        }

        return null;
    }

    public function gatewayFor(string $name): ?PaymentGateway
    {
        return $this->gateways->get($name);
    }
}
