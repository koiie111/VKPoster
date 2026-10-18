<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Referral\ReferralService;
use App\Domain\Billing\Referral\ReferralSettings;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * `/r/{code}`: the invitation link. It remembers who invited in a cookie (the programme's term, 30 days unless the owner changed it) and
 * sends the visitor to registration. An unknown code is not an error for the visitor: they just register without an inviter.
 */
final class ReferralLinkController
{
    public function __construct(private readonly ReferralService $referrals, private readonly ReferralSettings $settings)
    {
    }

    public function follow(Request $request): Response
    {
        $params = $request->attribute('route_params');
        $code = is_array($params) && is_string($params['code'] ?? null) ? strtoupper($params['code']) : '';
        if ($code === '') {
            throw new HttpException(404, 'Not found');
        }
        $response = Response::redirect('/register');
        if ($this->settings->enabled() && $this->referrals->referrerByCode($code) !== null) {
            $response = $response->withCookie(ReferralService::COOKIE, $code, $this->settings->cookieDays() * 86400, $request->isSecure());
        }

        return $response;
    }
}
