<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Billing\PlanEditor;
use App\Domain\Billing\PlanRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Money;

/**
 * Admin: subscriptions, payments with refunds, and the price list (plans, prices, limits).
 */
final class BillingAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminDirectory $directory,
        private readonly PaymentRepository $payments,
        private readonly BillingService $billing,
        private readonly PlanRepository $plans,
        private readonly PlanEditor $editor,
        private readonly FormFlash $flash,
    ) {
    }

    public function payments(Request $request): Response
    {
        $status = WorkspaceRequest::text($request->input('status'));
        $query = WorkspaceRequest::text($request->input('q'));
        $page = $request->input('page');
        $params = array_filter(['status' => $status, 'q' => $query], static fn (string $v): bool => $v !== '');

        return $this->view->response('admin/billing/payments.twig', [
            'status' => $status,
            'q' => $query,
            'result' => $this->directory->payments($status, $query, is_string($page) && ctype_digit($page) ? (int) $page : 1),
            'base' => '/admin/payments' . ($params === [] ? '' : '?' . http_build_query($params)),
        ]);
    }

    public function subscriptions(Request $request): Response
    {
        $status = WorkspaceRequest::text($request->input('status'));
        $page = $request->input('page');

        return $this->view->response('admin/billing/subscriptions.twig', [
            'status' => $status,
            'result' => $this->directory->subscriptions($status, is_string($page) && ctype_digit($page) ? (int) $page : 1),
            'base' => '/admin/subscriptions' . ($status === '' ? '' : '?status=' . rawurlencode($status)),
        ]);
    }

    public function refund(Request $request, string $paymentId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $payment = $this->payments->findByPublicId($paymentId) ?? throw new HttpException(404, 'Not found');
        if ($request->input('confirm') !== '1') {
            $this->flash->toast('Подтвердите, что возврат нельзя отменить.', 'error');

            return Response::redirect('/admin/payments');
        }
        $left = $payment->amount - $payment->refundedAmount;
        $raw = trim(str_replace([' ', "\u{00A0}"], '', WorkspaceRequest::text($request->input('amount'))));
        $amount = $left;
        if ($raw !== '') {
            $parsed = Money::fromDecimal(str_replace(',', '.', $raw));
            $amount = $parsed ?? 0;
        }
        try {
            $this->billing->refund($payment, $amount, $staff->id);
            $this->flash->toast('Возврат ' . Money::format($amount, $payment->currency) . ' выполнен.');
        } catch (BillingException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }

        return Response::redirect('/admin/payments');
    }

    public function plans(): Response
    {
        return $this->view->response('admin/billing/plans.twig', ['plans' => $this->plans->all()]);
    }

    public function editPlan(string $code): Response
    {
        $plan = $this->plans->findByCode($code) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/billing/plan_edit.twig', [
            'plan' => $plan,
            'limits' => PlanEditor::LIMITS,
            'features' => PlanEditor::FEATURES,
        ]);
    }

    public function updatePlan(Request $request, string $code): Response
    {
        $plan = $this->plans->findByCode($code) ?? throw new HttpException(404, 'Not found');
        $input = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $value;
            }
        }
        $errors = $this->editor->update($plan, $input, WorkspaceRequest::user($request)->id);
        if ($errors !== []) {
            // A box that was left unchecked is absent from the form data: remember it as "off", or it would come back ticked.
            $input += ['is_public' => '0'];
            foreach (array_keys(PlanEditor::FEATURES) as $key) {
                $input += ['feature_' . $key => '0'];
            }
            $this->flash->invalid($input, ['form' => $errors]);

            return Response::redirect('/admin/plans/' . $plan->code);
        }
        $this->flash->toast('Тариф «' . $plan->name . '» сохранён. Новые цены действуют для следующих платежей.');

        return Response::redirect('/admin/plans');
    }
}
