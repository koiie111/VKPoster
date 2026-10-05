<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Legal\LegalDocuments;
use App\Domain\User\User;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Sends a signed-in person to `/consent` when the legal documents changed since they last agreed (their stored version is not the
 * current one). Must run after `Authenticate`. JSON clients get 403 with a hint instead of a redirect.
 */
final class RequireConsent implements MiddlewareInterface
{
    public function __construct(private readonly LegalDocuments $documents)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User || $user->consentVersion === $this->documents->consentVersion()) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            throw new HttpException(403, 'Consent required');
        }
        return Response::redirect('/consent');
    }
}
