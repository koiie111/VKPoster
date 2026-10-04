<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Workspace\AcceptStatus;
use App\Domain\Workspace\TeamService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The page behind the link in an invitation email, and accepting it. The page is public (the link is the
 * secret); accepting needs a signed-in account with a confirmed email. A guest is sent to sign in or sign up
 * and brought back here afterwards.
 */
final class InvitationController
{
    public function __construct(
        private readonly View $view,
        private readonly TeamService $team,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(string $token): Response
    {
        $preview = $this->team->preview($token);
        if ($preview === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'invitation'], 410);
        }
        $session = $this->flash->session();
        $signedIn = $session->has('auth.user_id');
        if (!$signedIn) {
            // After signing in (or up and confirming the email) the visitor returns to this page.
            $session->set('auth.intended', '/invitations/' . $token);
        }

        return $this->view->response('workspace/invitation.twig', [
            'invitation' => $preview['invitation'],
            'workspace' => $preview['workspace'],
            'role_label' => $preview['invitation']->role->label(),
            'role_description' => $preview['invitation']->role->description(),
            'signed_in' => $signedIn,
            'token' => $token,
            'mismatch' => false,
            'user_email' => null,
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $user = WorkspaceRequest::user($request);
        [$status, $workspace] = $this->team->accept($user, $token);
        if ($status === AcceptStatus::Invalid || $workspace === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'invitation'], 410);
        }
        if ($status === AcceptStatus::EmailMismatch) {
            $preview = $this->team->preview($token);
            if ($preview === null) {
                return $this->view->response('auth/link_expired.twig', ['what' => 'invitation'], 410);
            }

            return $this->view->response('workspace/invitation.twig', [
                'invitation' => $preview['invitation'],
                'workspace' => $workspace,
                'role_label' => $preview['invitation']->role->label(),
                'role_description' => $preview['invitation']->role->description(),
                'signed_in' => true,
                'token' => $token,
                'mismatch' => true,
                'user_email' => $user->email,
            ], 403);
        }
        $this->flash->toast($status === AcceptStatus::Joined
            ? 'Вы в команде пространства «' . $workspace->name . '».'
            : 'Вы уже состоите в пространстве «' . $workspace->name . '».');

        return Response::redirect('/w/' . $workspace->publicId);
    }
}
