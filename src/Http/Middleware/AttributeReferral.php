<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Billing\Referral\ReferralService;
use App\Domain\User\User;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * After the visitor has registered and signed in, ties the new account to the person whose invitation link they followed (the cookie set
 * by `/r/{code}`). It runs once: whatever the outcome, the cookie is dropped. Old accounts are ignored by the service.
 */
final class AttributeReferral implements MiddlewareInterface
{
    public function __construct(private readonly ReferralService $referrals)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $code = $request->cookie(ReferralService::COOKIE);
        $user = $request->attribute('user');
        if ($code === null || $code === '' || !$user instanceof User) {
            return $response;
        }
        try {
            $this->referrals->attribute($user, $code, $request->ip());
        } catch (\Throwable) {
            // An invitation must never break the page the person asked for.
        }

        return $response->withCookie(ReferralService::COOKIE, '', -1, $request->isSecure());
    }
}
