<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\User\User;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Keeps people with an unconfirmed email out of the application: they are sent to the "check your mail"
 * page. Must run after `Authenticate`.
 */
final class RequireVerifiedEmail implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute('user');
        // An account without an email (made through a social network) has nothing to confirm.
        if (!$user instanceof User || $user->isVerified() || $user->email === null) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            throw new HttpException(403, 'Email not verified');
        }

        return Response::redirect('/email/verification');
    }
}
