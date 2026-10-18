<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\Referral\ReferralService;
use App\Domain\Billing\Referral\ReferralSettings;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Domain\Audit\AuditLog;
use App\Support\Money;

/**
 * Admin: the referral programme. Terms (share, link lifetime, smallest withdrawal), withdrawal requests that staff pay by hand, and links
 * held back as suspected self-invitations. Changing terms and handling money need the one-time code.
 */
final class ReferralAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $settings,
        private readonly StepUp $stepUp,
        private readonly FormFlash $flash,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->response('admin/billing/referrals.twig', [
            'enabled' => $this->settings->enabled(),
            'percent' => $this->settings->percent(),
            'cookie_days' => $this->settings->cookieDays(),
            'min_payout' => rtrim(rtrim(number_format($this->settings->minPayout() / 100, 2, '.', ''), '0'), '.'),
            'payouts' => $this->referrals->payouts(null, 100),
            'flagged' => $this->referrals->flagged(),
            'top' => $this->referrals->top(),
            'tz' => WorkspaceRequest::user($request)->timezone,
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/referrals')) !== null) {
            return $denied;
        }
        $errors = $this->settings->save(
            $request->input('enabled') === '1',
            AdminInput::text($request, 'percent', 3),
            AdminInput::text($request, 'cookie_days', 3),
            AdminInput::text($request, 'min_payout', 10),
            $staff->id,
        );
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/referrals');
        }
        $this->audit->record('admin.referral_settings_changed', $staff->id, 'referral', 'settings', ['percent' => $this->settings->percent(), 'enabled' => $this->settings->enabled() ? '1' : '0']);
        $this->flash->toast('Условия рефералки сохранены.');

        return Response::redirect('/admin/referrals');
    }

    public function payout(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $params = $request->attribute('route_params');
        $id = is_array($params) && is_string($params['id'] ?? null) ? $params['id'] : '';
        $paid = is_array($params) && ($params['action'] ?? '') === 'paid';
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/referrals')) !== null) {
            return $denied;
        }
        try {
            $this->referrals->handlePayout($id, $paid, AdminInput::text($request, 'note', 300), $staff->id);
        } catch (BillingException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect('/admin/referrals');
        }
        $this->flash->toast($paid ? 'Заявка отмечена выплаченной.' : 'Заявка отклонена, деньги вернулись на баланс.');

        return Response::redirect('/admin/referrals');
    }

    public function decide(Request $request): Response
    {
        $params = $request->attribute('route_params');
        if (!is_array($params) || !is_string($params['id'] ?? null) || !in_array($params['action'] ?? '', ['approve', 'reject'], true)) {
            throw new HttpException(404, 'Not found');
        }
        $this->referrals->decide((int) $params['id'], $params['action'] === 'approve', WorkspaceRequest::user($request)->id);
        $this->flash->toast($params['action'] === 'approve' ? 'Ссылка одобрена: бонусы будут начисляться.' : 'Ссылка отклонена.');

        return Response::redirect('/admin/referrals');
    }
}
