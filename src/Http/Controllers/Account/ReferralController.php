<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\Referral\ReferralService;
use App\Domain\Billing\Referral\ReferralSettings;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Money;

/**
 * "Приглашения": the person's own link, how many people came through it, the balance and the request to withdraw it. The balance can also
 * pay for a plan on the billing page.
 */
final class ReferralController
{
    public function __construct(
        private readonly View $view,
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $settings,
        private readonly FormFlash $flash,
        private readonly Config $config,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $zone = $user->timezone;

        return $this->view->response('account/referrals.twig', [
            'enabled' => $this->settings->enabled(),
            'percent' => $this->settings->percent(),
            'cookie_days' => $this->settings->cookieDays(),
            'link' => rtrim($this->config->string('app.url'), '/') . '/r/' . $this->referrals->codeFor($user->id),
            'summary' => $this->referrals->summary($user->id),
            'balance' => $this->referrals->balance($user->id),
            'min_payout' => $this->settings->minPayout(),
            'payouts' => $this->referrals->payoutsOf($user->id),
            'tz' => $zone,
        ]);
    }

    public function withdraw(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $text = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], WorkspaceRequest::text($request->input('amount')));
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $text) !== 1) {
            $this->flash->toast('Напишите сумму в рублях.', 'error');

            return Response::redirect('/account/referrals');
        }
        try {
            $this->referrals->requestPayout($user, (int) round((float) $text * 100), WorkspaceRequest::text($request->input('details')));
        } catch (BillingException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect('/account/referrals');
        }
        $this->flash->toast('Заявка принята. Мы переведём ' . Money::format((int) round((float) $text * 100)) . ' вручную и напишем вам.');

        return Response::redirect('/account/referrals');
    }
}
