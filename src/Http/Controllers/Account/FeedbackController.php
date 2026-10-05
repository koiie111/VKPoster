<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Notification\FeedbackMailer;
use App\Domain\Support\Tickets;
use App\Http\FormFlash;
use App\Http\WorkspaceNav;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * "Сообщить о проблеме": a short form whose message goes to the support mailbox together with context (see `FeedbackMailer`).
 */
final class FeedbackController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly FeedbackMailer $mailer,
        private readonly WorkspaceNav $nav,
        private readonly FormFlash $flash,
        private readonly Tickets $tickets,
    ) {
    }

    public function show(Request $request): Response
    {
        $given = WorkspaceRequest::text($request->input('from'));

        return $this->view->response('account/feedback.twig', ['from' => $this->page($given === '' ? $this->refererPath($request) : $given)]);
    }

    public function send(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $message = WorkspaceRequest::text($request->input('message'));
        $from = $this->page(WorkspaceRequest::text($request->input('from')));
        $errors = $this->validator->make(['message' => $message], ['message' => 'required|string|min:10|max:' . FeedbackMailer::MAX_LENGTH], ['message' => 'Сообщение'])->errors();
        if ($errors !== []) {
            $this->flash->invalid(['message' => $message], $errors);

            return Response::redirect('/feedback' . ($from === '' ? '' : '?from=' . rawurlencode($from)));
        }
        $this->mailer->send($user, $this->nav->current(), $message, $from, $request->header('user-agent') ?? '');
        // The same message is a ticket in the admin area, with what the service knows about the person's plan and failed posts.
        $this->tickets->open($user, $user->email, mb_substr((string) preg_replace('/\s+/', ' ', $message), 0, 80), $message, 'form', $this->tickets->contextFor($user) + ($from === '' ? [] : ['page' => $from]));
        $this->flash->toast('Спасибо! Мы получили сообщение и ответим на вашу почту.');

        return Response::redirect('/app');
    }

    /**
     * Only a path on this site is kept (nothing that could carry a token in its query string).
     */
    private function page(?string $value): string
    {
        if ($value === null || !Response::isRelativeUrl($value)) {
            return '';
        }

        return mb_substr(explode('?', explode('#', $value)[0])[0], 0, 200);
    }

    private function refererPath(Request $request): ?string
    {
        $referer = $request->header('referer');
        $path = $referer === null ? null : parse_url($referer, PHP_URL_PATH);

        return is_string($path) ? $path : null;
    }
}
