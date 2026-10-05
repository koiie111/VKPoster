<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Audit\AuditLog;
use App\Domain\Legal\LegalDocuments;
use App\Domain\User\UserRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * "The documents changed": shows the current required documents and records the person's agreement for the new version.
 */
final class ConsentController
{
    public function __construct(
        private readonly View $view,
        private readonly LegalDocuments $documents,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        if ($user->consentVersion === $this->documents->consentVersion()) {
            return Response::redirect('/app');
        }

        return $this->view->response('account/consent.twig', ['documents' => $this->documents->required(), 'first_time' => $user->consentVersion === null]);
    }

    public function accept(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        if ($request->input('consent') !== '1') {
            $this->flash->invalid([], ['consent' => ['Чтобы продолжить, нужно согласиться с документами.']]);

            return Response::redirect('/consent');
        }
        $version = $this->documents->consentVersion();
        $this->users->recordConsent($user->id, $version, $request->ip());
        $this->audit->record('auth.consent.accepted', $user->id, 'user', (string) $user->id, ['version' => $version]);

        return Response::redirect('/app');
    }
}
