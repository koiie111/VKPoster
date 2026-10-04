<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Auth\EmailChangeService;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\PasswordService;
use App\Domain\Auth\SessionRegistry;
use App\Domain\Auth\TwoFactorService;
use App\Domain\User\User;
use App\Http\FormFlash;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;
use App\Support\UserAgent;
use LogicException;

/**
 * "Security" page: password, email, two-factor status, signed-in devices and the sign-in journal.
 */
final class SecurityController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly PasswordPolicy $policy,
        private readonly PasswordService $passwords,
        private readonly EmailChangeService $emailChange,
        private readonly SessionRegistry $sessions,
        private readonly LoginService $login,
        private readonly TwoFactorService $twoFactor,
        private readonly FormFlash $flash,
        private readonly Config $config,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $session = $this->flash->session();
        $devices = array_map(
            static fn (array $row): array => $row + ['label' => UserAgent::describe($row['user_agent'])],
            $this->sessions->list($user->id, $session->id(), $this->config->int('session.idle_ttl', 7200)),
        );
        $journal = array_map(
            static fn (array $row): array => $row + ['label' => UserAgent::describe($row['user_agent'])],
            $this->login->recent($user->id),
        );

        return $this->view->response('account/security.twig', [
            'user' => $user,
            'devices' => $devices,
            'journal' => $journal,
            'recovery_left' => $user->hasTwoFactor() ? $this->twoFactor->remainingRecoveryCodes($user->id) : 0,
            'has_password' => $user->passwordHash !== null,
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->user($request);
        $current = $this->string($request->input('current_password'));
        $new = $this->string($request->input('password'));
        $validation = $this->validator->make(
            ['current_password' => $current, 'password' => $new, 'password_confirmation' => $request->input('password_confirmation')],
            ['current_password' => 'required|string', 'password' => 'required|string|confirmed'],
            ['current_password' => 'Текущий пароль', 'password' => 'Новый пароль'],
        );
        $errors = $validation->errors();
        if (!isset($errors['password'])) {
            $problem = $this->policy->check($new, $user->email);
            if ($problem !== null) {
                $errors['password'] = [$problem];
            }
        }
        if ($errors === []) {
            $outcome = $this->passwords->change($user, $current, $new, $this->flash->session()->id());
            if ($outcome === 'wrong_password') {
                $errors['current_password'] = ['Текущий пароль указан неверно.'];
            } elseif ($outcome === 'throttled') {
                $errors['current_password'] = ['Слишком много попыток. Подождите 10 минут и повторите.'];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid([], $errors);

            return Response::redirect('/account/security#password');
        }
        $this->flash->toast('Пароль изменён. На других устройствах вы вышли из аккаунта.');

        return Response::redirect('/account/security');
    }

    public function requestEmailChange(Request $request): Response
    {
        $user = $this->user($request);
        $email = mb_strtolower($this->string($request->input('new_email')));
        $password = $this->string($request->input('email_password'));
        $validation = $this->validator->make(
            ['new_email' => $email, 'email_password' => $password],
            ['new_email' => 'required|string|email|max:254', 'email_password' => 'required|string'],
            ['new_email' => 'Новая почта', 'email_password' => 'Пароль'],
        );
        $errors = $validation->errors();
        if ($errors === []) {
            $check = $this->passwords->confirmPassword($user, $password);
            if ($check === 'wrong_password') {
                $errors['email_password'] = ['Пароль указан неверно.'];
            } elseif ($check === 'throttled') {
                $errors['email_password'] = ['Слишком много попыток. Подождите 10 минут и повторите.'];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid(['new_email' => $email], $errors);

            return Response::redirect('/account/security#email');
        }
        // The answer is the same whether or not the address is already registered.
        $this->emailChange->request($user, $email);
        $this->flash->toast('Мы отправили письмо со ссылкой на новый адрес. Почта изменится после подтверждения.');

        return Response::redirect('/account/security');
    }

    public function emailConfirmShow(string $token): Response
    {
        $email = $this->emailChange->pendingEmail($token);
        if ($email === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'email'], 410);
        }

        return $this->view->response('auth/email_change_confirm.twig', ['token' => $token, 'new_email' => $email]);
    }

    public function emailConfirm(string $token): Response
    {
        $user = $this->emailChange->confirm($token);
        if ($user === null) {
            return $this->view->response('auth/link_expired.twig', ['what' => 'email'], 410);
        }
        $signedIn = $this->flash->session()->get('auth.user_id') === $user->id;
        $this->flash->toast('Адрес почты изменён.');

        return Response::redirect($signedIn ? '/account/security' : '/login');
    }

    public function revokeSession(Request $request, string $id): Response
    {
        $user = $this->user($request);
        if ($this->sessions->revoke($user->id, $id, $this->flash->session()->id())) {
            $this->flash->toast('Устройство отключено.');
        } else {
            $this->flash->toast('Не удалось отключить это устройство. Обновите страницу и попробуйте снова.', 'error');
        }

        return Response::redirect('/account/security#devices');
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user : throw new LogicException('Authenticate middleware is missing.');
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
