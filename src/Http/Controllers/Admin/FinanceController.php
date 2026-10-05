<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Admin\FinanceExport;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\PaymentRepository;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Csv;

/**
 * Admin: one payment in detail (with what the provider told us and the money journal), checking a payment against the provider, the journal of
 * provider notifications with a "handle again", and the export for the accountant with the provider fees.
 */
final class FinanceController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminDirectory $directory,
        private readonly PaymentRepository $payments,
        private readonly BillingService $billing,
        private readonly FinanceExport $export,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function payment(string $paymentId): Response
    {
        $payment = $this->directory->payment($paymentId) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/billing/payment.twig', [
            'payment' => $payment,
            'webhooks' => $this->directory->paymentWebhooks((string) $payment['provider'], is_string($payment['provider_payment_id']) ? $payment['provider_payment_id'] : null),
            'ledger' => $this->directory->paymentLedger((string) $payment['public_id']),
            'can_refund' => $payment['status'] === 'succeeded' && (int) $payment['amount'] - (int) $payment['refunded_amount'] > 0,
        ]);
    }

    /**
     * Ask the provider what really happened to the payment and apply it (the same code as a notification would run).
     */
    public function reconcile(Request $request, string $paymentId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $payment = $this->payments->findByPublicId($paymentId) ?? throw new HttpException(404, 'Not found');
        $before = $payment->status->value;
        try {
            $outcome = $this->billing->sync($payment);
            $after = ($this->payments->findById($payment->id)?->status->value) ?? $before;
            $this->audit->record('admin.payment_reconciled', $staff->id, 'payment', $payment->publicId, ['before' => $before, 'after' => $after, 'outcome' => $outcome->value], $payment->workspaceId);
            $this->flash->toast($before === $after ? 'Провайдер подтвердил то, что у нас записано: ничего не изменилось.' : 'Статус обновлён по данным провайдера: ' . $before . ' → ' . $after . '.');
        } catch (\Throwable) {
            $this->audit->record('admin.payment_reconciled', $staff->id, 'payment', $payment->publicId, ['before' => $before, 'outcome' => 'error'], $payment->workspaceId);
            $this->flash->toast('Не удалось спросить провайдера. Попробуйте позже или проверьте ключи.', 'error');
        }

        return Response::redirect('/admin/payments/' . $payment->publicId);
    }

    public function webhooks(Request $request): Response
    {
        $provider = AdminInput::choice($request, 'provider', ['yookassa', 'tbank', 'fake']);
        $outcome = AdminInput::choice($request, 'outcome', ['received', 'processed', 'ignored', 'rejected']);
        $params = ['provider' => $provider, 'outcome' => $outcome];

        return $this->view->response('admin/billing/webhooks.twig', [
            'filters' => $params,
            'result' => $this->directory->webhooks($provider, $outcome, AdminInput::page($request)),
            'base' => '/admin/webhooks' . AdminInput::query($params),
        ]);
    }

    public function webhook(string $id): Response
    {
        $event = $this->directory->webhook((int) $id) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/billing/webhook.twig', ['event' => $event]);
    }

    /**
     * Handle a notification again: the provider is asked about the payment it names (we keep a masked copy of the notification, not the
     * signed original, so the provider's own answer is the source of truth).
     */
    public function replay(Request $request, string $id): Response
    {
        $event = $this->directory->webhook((int) $id) ?? throw new HttpException(404, 'Not found');
        $payment = $event['payment_public_id'] === null ? null : $this->payments->findByPublicId((string) $event['payment_public_id']);
        if ($payment === null) {
            $this->flash->toast('По этому уведомлению нет платежа у нас: обрабатывать нечего.', 'error');

            return Response::redirect('/admin/webhooks/' . (int) $id);
        }

        return $this->reconcile($request, $payment->publicId);
    }

    public function exportCsv(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $from = AdminInput::date($request, 'from', $staff->timezone);
        $to = AdminInput::date($request, 'to', $staff->timezone, true);
        if ($from === null || $to === null || $from >= $to || $from->diff($to)->days > 400) {
            $this->flash->toast('Укажите период выгрузки: даты «с» и «по», не больше 13 месяцев.', 'error');

            return Response::redirect('/admin/payments');
        }
        $csv = Csv::build(FinanceExport::header(), $this->export->rows($from, $to));
        $this->audit->record('admin.finance_exported', $staff->id, null, null, ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'bytes' => strlen($csv)]);

        return (new Response(200, $csv))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="payments-' . $from->format('Y-m-d') . '_' . $to->modify('-1 day')->format('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function saveFees(Request $request): Response
    {
        $input = $request->input('fee');
        $stored = $this->export->saveFees(is_array($input) ? $input : [], WorkspaceRequest::user($request)->id);
        $this->audit->record('admin.settings_changed', WorkspaceRequest::user($request)->id, 'setting', FinanceExport::SETTING, ['after' => json_encode($stored)]);
        $this->flash->toast('Комиссии сохранены: они нужны только для оценки в выгрузке.');

        return Response::redirect('/admin/payments');
    }
}
