<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LoginService;
use App\Domain\Auth\LoginStatus;
use App\Domain\Auth\RememberMe;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\User;
use App\Http\Auth\SessionAuth;
use App\Http\Auth\SocialFlow;
use App\Integrations\OAuth\ProviderRegistry;
use App\Domain\Auth\Social\SocialAuthService;
use App\Http\FormFlash;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Sign-in (password, then TOTP or recovery code when 2FA is on) and sign-out.
 */
final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly LoginService $login,
        private readonly SessionAuth $auth,
        private readonly FormFlash $flash,
        private readonly SessionRegistry $sessions,
        private readonly RememberMe $remember,
        private readonly SocialFlow $social,
        private readonly SocialAuthService $socialAuth,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function show(): Response
    {
        return $this->view->response('auth/login.twig', [
            'social' => ['buttons' => $this->social->buttons(), 'telegram' => $this->social->telegramWidget('login', null)],
        ]);
    }

    public function store(Request $request): Response
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $email = is_string($email) ? trim($email) : '';
        $old = ['email' => $email, 'remember' => $request->input('remember') === '1' ? '1' : ''];
        if ($email === '' || !is_string($password) || $password === '') {
            $this->flash->invalid($old, ['form' => 'Введите почту и пароль.']);

            return Response::redirect('/login');
        }
        // Argon2 on megabytes of input would be a cheap way to burn CPU.
        $result = strlen($password) > 1024 || strlen($email) > 254
            ? new \App\Domain\Auth\LoginResult(LoginStatus::Invalid)
            : $this->login->attempt($email, $password, $request->ip(), $request->header('user-agent') ?? '');
        $remember = $old['remember'] === '1';

        switch ($result->status) {
            case LoginStatus::Success:
                return $this->finish($request, $result->user ?? throw new \LogicException('Missing user.'), $remember);
            case LoginStatus::NeedsTwoFactor:
                $this->auth->beginTwoFactor($this->flash->session(), $result->user ?? throw new \LogicException('Missing user.'), $remember);

                return Response::redirect('/login/2fa');
            case LoginStatus::Locked:
                $this->flash->invalid($old, ['form' => sprintf('Слишком много попыток входа. Подождите %s и повторите.', self::wait($result->retryAfter))]);

                return Response::redirect('/login');
            case LoginStatus::Blocked:
                $this->flash->invalid($old, ['form' => 'Аккаунт заблокирован. Напишите в поддержку, и мы разберёмся.']);

                return Response::redirect('/login');
            case LoginStatus::Invalid:
                $this->flash->invalid($old, ['form' => 'Неверная почта или пароль. Проверьте данные и попробуйте ещё раз.']);

                return Response::redirect('/login');
        }
    }

    public function twoFactorShow(): Response
    {
        if ($this->auth->pendingTwoFactor($this->flash->session()) === null) {
            return Response::redirect('/login');
        }

        return $this->view->response('auth/two_factor.twig');
    }

    public function twoFactorStore(Request $request): Response
    {
        $pending = $this->auth->pendingTwoFactor($this->flash->session());
        if ($pending === null) {
            return Response::redirect('/login');
        }
        $code = $request->input('code');
        $code = is_string($code) ? trim($code) : '';
        $outcome = $code === '' ? 'invalid' : $this->login->checkSecondFactor($pending['user'], $code, $request->ip(), $request->header('user-agent') ?? '');
        if ($outcome === 'ok') {
            return $this->finish($request, $pending['user'], $pending['remember']);
        }
        $this->flash->invalid([], ['code' => $outcome === 'throttled'
            ? 'Слишком много попыток. Подождите 10 минут и повторите.'
            : 'Код не подошёл. Проверьте код в приложении или введите резервный код.']);

        return Response::redirect('/login/2fa');
    }

    public function logout(Request $request): Response
    {
        $this->auth->signOut($request, $this->flash->session());

        return $this->auth->withoutRememberCookie(Response::redirect('/login'));
    }

    public function logoutAll(Request $request): Response
    {
        $user = $request->attribute('user');
        if ($user instanceof User) {
            $this->sessions->revokeAll($user->id);
            $this->remember->revokeAll($user->id);
        }
        $this->auth->signOut($request, $this->flash->session());

        return $this->auth->withoutRememberCookie(Response::redirect('/login'));
    }

    private function finish(Request $request, User $user, bool $remember): Response
    {
        $session = $this->flash->session();
        $intended = $session->get('auth.intended');
        // A provider account held back because its verified email matched this account: now that the
        // password is proven, attach it.
        $pending = $this->social->takePendingLink();
        $cookie = $this->auth->signIn($request, $session, $user, $remember);
        $session->forget('auth.intended');
        if ($pending !== null && $this->socialAuth->linkAfterPassword($user, $pending)) {
            $this->flash->toast($this->providers->label($pending->provider) . ' привязан: теперь им можно входить в аккаунт.');
        }
        $target = is_string($intended) && Response::isRelativeUrl($intended) ? $intended : '/app';
        $response = Response::redirect($target);

        return $cookie === null ? $response : $this->auth->withRememberCookie($response, $cookie);
    }

    private static function wait(int $seconds): string
    {
        return $seconds >= 90 ? sprintf('%d мин.', (int) ceil($seconds / 60)) : sprintf('%d сек.', max(1, $seconds));
    }
}
