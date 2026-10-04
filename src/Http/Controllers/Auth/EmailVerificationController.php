<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\RegistrationService;
use App\Domain\User\User;
use App\Http\FormFlash;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Email confirmation. Opening the link only shows a button; the POST behind it does the work, so
 * mail scanners that pre-fetch links cannot confirm (or burn) a token.
 */
final class EmailVerificationController
{
    public function __construct(
        private readonly View $view,
        private readonly RegistrationService $registration,
        private readonly FormFlash $flash,
    ) {
    }

    /**
     * "Check your mail" page for a signed-in user who has not confirmed yet.
     */
    public function notice(Request $request): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User || $user->isVerified()) {
            return Response::redirect('/app');
        }

        return $this->view->response('auth/verify_notice.twig', ['email' => $user->email]);
    }

    public function resend(Request $request): Response
    {
        $user = $request->attribute('user');
        if (!$user instanceof User || $user->isVerified()) {
            return Response::redirect('/app');
        }
        if ($this->registration->sendVerification($user)) {
            $this->flash->toast('Отправили новое письмо. Проверьте почту, оно придёт в течение минуты.');
        } else {
            $this->flash->toast('Письмо уже отправлено. Новое можно запросить через некоторое время.', 'warning');
        }

        return Response::redirect('/email/verification');
    }

    public function show(string $token): Response
    {
        if (!$this->registration->isValidLink($token)) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'verify'], 410);
        }

        return $this->view->response('auth/verify_confirm.twig', ['token' => $token]);
    }

    public function confirm(string $token): Response
    {
        $user = $this->registration->confirm($token);
        if ($user === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'verify'], 410);
        }
        $signedIn = $this->flash->session()->get('auth.user_id') === $user->id;
        $this->flash->toast($signedIn ? 'Почта подтверждена. Добро пожаловать!' : 'Почта подтверждена. Теперь можно войти.');

        return Response::redirect($signedIn ? '/app' : '/login');
    }
}
