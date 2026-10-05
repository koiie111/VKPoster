<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Audit\AuditLog;
use App\Domain\Notification\MarketingConsent;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * "Unsubscribe from news" from the link at the bottom of a marketing email. No sign-in: the signature in the link says whom it is for.
 * The page asks once (so a mail scanner opening the link does not unsubscribe anybody); the same URL also takes the one-click POST that mail
 * programs send for the `List-Unsubscribe-Post` header (RFC 8058), which is why that route has no CSRF token: the signature is the proof.
 */
final class UnsubscribeController
{
    public function __construct(private readonly View $view, private readonly MarketingConsent $consent, private readonly AuditLog $audit)
    {
    }

    public function show(Request $request, string $user): Response
    {
        $who = $this->consent->verify($request->path, $request->query) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('site/unsubscribe.twig', [
            'already' => !$this->consent->isSubscribed($who['user']),
            'action' => $request->path . '?' . http_build_query($request->query),
            'done' => false,
        ]);
    }

    public function confirm(Request $request, string $user): Response
    {
        $who = $this->consent->verify($request->path, $request->query) ?? throw new HttpException(404, 'Not found');
        $this->consent->unsubscribe($who['user'], $who['campaign']);
        $this->audit->record('marketing.unsubscribed', $who['user'], 'user', (string) $who['user'], $who['campaign'] === null ? [] : ['campaign' => $who['campaign']]);
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return Response::text('OK');
        }

        return $this->view->response('site/unsubscribe.twig', ['already' => true, 'action' => '', 'done' => true]);
    }
}
