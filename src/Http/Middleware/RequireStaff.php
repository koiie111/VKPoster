<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\User\User;
use App\Http\Auth\Impersonation;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\View\View;
use Closure;

/**
 * Door of `/admin`. Anybody who is not a superadmin gets a plain 404 (the area does not admit it exists). A superadmin without two-factor
 * protection is stopped on a page that explains how to switch it on: staff accounts can see everyone's data, so a password alone is not enough.
 * A session that is "acting as a customer" never reaches the admin area. Must run after `Authenticate`.
 */
final class RequireStaff implements MiddlewareInterface
{
    public function __construct(private readonly View $view, private readonly Impersonation $impersonation)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User || !$user->isSuperadmin || $this->impersonation->active()) {
            throw new HttpException(404, 'Not found');
        }
        if (!$user->hasTwoFactor()) {
            return $this->view->response('admin/two_factor_required.twig', [], 403);
        }

        return $next($request);
    }
}
