<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Auth\EmailChangeService;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\PasswordService;
use App\Domain\Auth\Social\IdentityRepository;
use App\Domain\Auth\Social\SocialAuthService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\Auth\SocialFlow;
use App\Http\FormFlash;
use App\Integrations\OAuth\ProviderRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;
use LogicException;

/**
 * "Sign-in methods" page: attach or detach VK ID, Yandex, Google and Telegram, and give an account created
 * through a social network its first password. The last remaining way to sign in can never be removed.
 */
final class LoginMethodsController
{
    public function __construct(
        private readonly View $view,
        private readonly ProviderRegistry $registry,
        private readonly IdentityRepository $identities,
        private readonly SocialAuthService $social,
        private readonly SocialFlow $flow,
        private readonly PasswordService $passwords,
        private readonly PasswordPolicy $policy,
        private readonly EmailChangeService $emailChange,
        private readonly LoginService $login,
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $linked = [];
        foreach ($this->identities->forUser($user->id) as $identity) {
            $linked[$identity->provider] = $identity;
        }
        // Enabled providers first, then any linked account whose provider has been switched off since (it can still be detached).
        $ids = array_values(array_unique([...$this->registry->enabledIds(), ...array_keys($linked)]));
        $rows = array_map(fn (string $id): array => [
            'id' => $id,
            'label' => $this->registry->label($id),
            'identity' => $linked[$id] ?? null,
            'enabled' => in_array($id, $this->registry->enabledIds(), true),
        ], $ids);
        $needsTelegram = $this->registry->telegram() !== null && !isset($linked['telegram']);

        return $this->view->response('account/login_methods.twig', [
            'user' => $user,
            'rows' => array_values(array_filter($rows, static fn (array $row): bool => $row['identity'] !== null || $row['enabled'])),
            'telegram' => $needsTelegram ? $this->flow->telegramWidget('link', $user->id) : null,
            'method_count' => $this->social->methodCount($user),
            'has_password_login' => $this->social->hasPasswordLogin($user),
            'has_password' => $user->passwordHash !== null,
        ]);
    }

    /**
     * Start attaching a provider account (POST, so that no outside page can start it by a link).
     */
    public function link(Request $request, string $provider): Response
    {
        $user = $this->user($request);
        if ($provider === 'telegram') {
            return Response::redirect('/account/login-methods');
        }

        return $this->flow->begin($provider, 'link', $user->id, null) ?? throw new HttpException(404, 'Not found');
    }

    public function unlink(Request $request, string $provider): Response
    {
        $user = $this->user($request);
        $label = $this->registry->label($provider);
        $outcome = $this->social->unlink($user, $provider);
        if ($outcome === 'ok') {
            $this->flash->toast($label . ' отвязан. Входить через него больше нельзя.');
        } elseif ($outcome === 'last') {
            $this->flash->toast('Это последний способ входа, отвязать его нельзя. Сначала задайте пароль или привяжите другой способ.', 'error');
        } else {
            $this->flash->toast('Этот способ входа уже отвязан.', 'info');
        }

        return Response::redirect('/account/login-methods');
    }

    /**
     * First password (and, if the account has none, the first email) for an account made through a social network.
     * With 2FA on, a code is required as well, since there is no old password to check.
     */
    public function setPassword(Request $request): Response
    {
        $user = $this->users->find($this->user($request)->id) ?? $this->user($request);
        if ($user->passwordHash !== null) {
            return Response::redirect('/account/security#password');
        }
        $password = $this->string($request->input('password'));
        $email = mb_strtolower($this->string($request->input('email')));
        $input = ['password' => $password, 'password_confirmation' => $request->input('password_confirmation')];
        $rules = ['password' => 'required|string|confirmed'];
        if ($user->email === null) {
            $input['email'] = $email;
            $rules['email'] = 'required|string|email|max:254';
        }
        $errors = $this->validator->make($input, $rules, ['password' => 'Пароль', 'email' => 'Почта'])->errors();
        if (!isset($errors['password'])) {
            $problem = $this->policy->check($password, $user->email ?? $email);
            if ($problem !== null) {
                $errors['password'] = [$problem];
            }
        }
        if ($errors === [] && $user->hasTwoFactor()) {
            $code = $this->string($request->input('code'));
            $outcome = $code === '' ? 'invalid' : $this->login->checkSecondFactor($user, $code, $request->ip(), $request->header('user-agent') ?? '');
            if ($outcome !== 'ok') {
                $errors['code'] = [$outcome === 'throttled' ? 'Слишком много попыток. Подождите 10 минут и повторите.' : 'Код не подошёл. Проверьте код в приложении или введите резервный код.'];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid($user->email === null ? ['email' => $email] : [], $errors);

            return Response::redirect('/account/login-methods#password');
        }
        $this->passwords->setInitial($user, $password, $this->flash->session()->id());
        if ($user->email === null) {
            // The address becomes the sign-in email once its owner clicks the link (same answer whether or not it is taken).
            $this->emailChange->request($user, $email);
            $this->flash->toast('Пароль задан. Мы отправили письмо со ссылкой на ' . $email . ': вход по почте заработает после подтверждения.');
        } else {
            $this->flash->toast('Пароль задан. Теперь можно входить по почте и паролю.');
        }

        return Response::redirect('/account/login-methods');
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user : throw new LogicException('Authenticate middleware is missing.');
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
