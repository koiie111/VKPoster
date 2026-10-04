<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\InvoiceRepository;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\ReceiptPdf;
use App\Domain\Billing\SubscriptionService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use Psr\Log\LoggerInterface;

/**
 * "Тариф и оплата": the current plan, usage, history and receipts, the plan comparison, the checkout and the page the customer
 * returns to after paying. Everything here is for the workspace owner (`workspace.billing`); the routes enforce it and `BillingService`
 * checks it again before it starts a payment.
 */
final class BillingController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly BillingView $pages,
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly PlanRepository $plans,
        private readonly ReceiptPdf $receipts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function overview(Request $request): Response
    {
        return $this->view->response('workspace/billing/index.twig', $this->pages->overview(WorkspaceRequest::context($request)));
    }

    public function plans(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $period = BillingPeriod::tryFrom(WorkspaceRequest::text($request->input('period'))) ?? BillingPeriod::Month;

        return $this->view->response('workspace/billing/plans.twig', $this->pages->plans($context, $period));
    }

    public function checkout(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $back = '/w/' . $context->workspacePublicId . '/billing/plans';
        $period = BillingPeriod::tryFrom(WorkspaceRequest::text($request->input('period')));
        $plan = WorkspaceRequest::text($request->input('plan'));
        if ($period === null || $plan === '') {
            $this->flash->toast('Выберите тариф и срок.', 'error');

            return Response::redirect($back);
        }
        try {
            $result = $this->billing->checkout(
                $context,
                WorkspaceRequest::user($request),
                $plan,
                $period,
                WorkspaceRequest::text($request->input('gateway')),
                $request->input('keep_card') === '1',
            );
        } catch (BillingException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($back . '?period=' . $period->value);
        }
        if ($result->paymentId === null) {
            $this->flash->toast((string) $result->message);

            return Response::redirect('/w/' . $context->workspacePublicId . '/billing');
        }

        // Not straight to the provider: a browser would refuse to follow a redirect from a form to another site (`form-action 'self'`).
        // The next hop is a plain page load of ours, which sends the customer on.
        return Response::redirect('/w/' . $context->workspacePublicId . '/billing/pay/' . $result->paymentId, 303);
    }

    /**
     * Send the customer to the provider's payment page for an open payment (also used to finish a payment that was left half-way).
     */
    public function pay(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $payment = $this->payments->findByPublicId(is_array($params) && is_string($params['paymentId'] ?? null) ? $params['paymentId'] : '');
        if ($payment === null || $payment->workspaceId !== $context->workspaceId) {
            throw new HttpException(404, 'Not found');
        }
        $invoice = $this->invoices->findById($payment->invoiceId);
        $url = $payment->confirmationUrl;
        if ($invoice === null || $invoice->status !== InvoiceStatus::Open || $payment->status !== PaymentStatus::Pending || $url === null) {
            $this->flash->toast('Эта оплата уже не действует. Выберите тариф ещё раз.', 'error');

            return Response::redirect('/w/' . $context->workspacePublicId . '/billing/plans');
        }
        if (Response::isRelativeUrl($url)) {
            return Response::redirect($url);
        }
        $host = parse_url($url, PHP_URL_HOST);

        return Response::redirectToTrusted($url, [is_string($host) ? $host : '']);
    }

    /**
     * Where the provider sends the customer back. The payment is asked about right here, so the page is correct even before the notification arrives.
     */
    public function returned(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $payment = $this->payments->findByPublicId(WorkspaceRequest::text($request->input('payment')));
        if ($payment === null || $payment->workspaceId !== $context->workspaceId) {
            throw new HttpException(404, 'Not found');
        }
        if ($payment->status === PaymentStatus::Pending) {
            try {
                $this->billing->sync($payment);
            } catch (\Throwable $e) {
                $this->logger->warning('billing.return_sync_failed', ['payment' => $payment->publicId, 'error' => $e->getMessage()]);
            }
            $payment = $this->payments->findById($payment->id) ?? $payment;
        }
        $invoice = $this->invoices->findById($payment->invoiceId) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('workspace/billing/return.twig', [
            'workspace' => $context,
            'state' => match ($payment->status) {
                PaymentStatus::Succeeded => 'paid',
                PaymentStatus::Failed => 'failed',
                default => 'pending',
            },
            'invoice' => $invoice,
            'invoice_payment' => $payment->publicId,
            'plan' => $this->plans->find($invoice->planId),
            'invoice_paid' => $invoice->status === InvoiceStatus::Paid,
            'base' => '/w/' . $context->workspacePublicId . '/billing',
        ]);
    }

    public function cancelRenewal(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->subscriptions->cancelRenewal($context->workspaceId, $context->userId);
        $this->flash->toast('Автопродление выключено. Тариф работает до конца оплаченного срока, потом пространство перейдёт на Free.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/billing');
    }

    public function resumeRenewal(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->subscriptions->resumeRenewal($context->workspaceId, $context->userId);
        $this->flash->toast('Автопродление включено.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/billing');
    }

    public function unschedule(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->subscriptions->clearScheduledChange($context->workspaceId, $context->userId);
        $this->flash->toast('Переход отменён: тариф останется прежним.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/billing');
    }

    public function forgetCard(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        try {
            $this->billing->forgetMethod($context, $context->userId);
        } catch (BillingException $e) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->flash->toast('Карта удалена. Автоматических списаний больше не будет: продлевайте тариф вручную.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/billing');
    }

    /**
     * The receipt of a paid invoice as a PDF file (404 for an invoice of another workspace or one that is not paid).
     */
    public function receipt(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $id = is_array($params) && is_string($params['invoiceId'] ?? null) ? $params['invoiceId'] : '';
        $invoice = $this->invoices->findForWorkspace($context->workspaceId, $id);
        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid) {
            throw new HttpException(404, 'Not found');
        }
        $payment = null;
        foreach ($this->payments->forInvoice($invoice->id) as $candidate) {
            if ($candidate->status === PaymentStatus::Succeeded || $candidate->status === PaymentStatus::Refunded) {
                $payment = $candidate;
                break;
            }
        }
        $plan = $this->plans->find($invoice->planId);
        $pdf = $this->receipts->render($invoice, $payment, $plan->name ?? '', $context->workspaceName, $context->timezone);

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="receipt-' . $invoice->number . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
