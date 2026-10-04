<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AuthTokens;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\PasswordService;
use App\Http\FormFlash;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * "Forgot password": ask for a link (same answer for any address), open the link, set a new password.
 */
final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly PasswordPolicy $policy,
        private readonly PasswordService $passwords,
        private readonly FormFlash $flash,
    ) {
    }

    public function forgotShow(): Response
    {
        return $this->view->response('auth/forgot.twig');
    }

    public function forgotStore(Request $request): Response
    {
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $validation = $this->validator->make(['email' => $email], ['email' => 'required|string|email|max:254'], ['email' => 'Почта']);
        if ($validation->fails()) {
            $this->flash->invalid(['email' => $email], $validation->errors());

            return Response::redirect('/password/forgot');
        }
        $this->passwords->requestReset($email);
        $this->flash->session()->flash('forgot.email', $email);

        return Response::redirect('/password/forgot/sent');
    }

    public function forgotSent(): Response
    {
        $email = $this->flash->session()->getFlash('forgot.email');
        if (!is_string($email)) {
            return Response::redirect('/password/forgot');
        }

        return $this->view->response('auth/forgot_sent.twig', ['email' => $email]);
    }

    public function resetShow(string $token): Response
    {
        if (!$this->passwords->isValidResetToken($token)) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'reset'], 410);
        }

        return $this->view->response('auth/reset.twig', ['token' => $token]);
    }

    public function resetStore(Request $request, string $token): Response
    {
        $password = $request->input('password');
        $input = ['password' => is_string($password) ? $password : '', 'password_confirmation' => $request->input('password_confirmation')];
        $validation = $this->validator->make($input, [
            'password' => 'required|string|confirmed',
        ], ['password' => 'Новый пароль']);
        $errors = $validation->errors();
        if (!isset($errors['password'])) {
            $problem = $this->policy->check($input['password']);
            if ($problem !== null) {
                $errors['password'] = [$problem];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid([], $errors);

            return Response::redirect('/password/reset/' . rawurlencode($token));
        }
        if (preg_match(AuthTokens::TOKEN_PATTERN, $token) !== 1 || $this->passwords->reset($token, $input['password']) === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'reset'], 410);
        }
        $this->flash->toast('Пароль изменён. Войдите с новым паролем.');

        return Response::redirect('/login');
    }
}
