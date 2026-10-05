<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LoginService;
use App\Domain\Auth\SessionRegistry;
use App\Domain\Auth\Social\SocialAuthService;
use App\Domain\Auth\Social\SocialStatus;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\Auth\SessionAuth;
use App\Http\Auth\SocialFlow;
use App\Http\FormFlash;
use App\Integrations\OAuth\OAuthException;
use App\Integrations\OAuth\ProviderRegistry;
use App\Integrations\OAuth\SocialProfile;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;
use Psr\Log\LoggerInterface;

/**
 * Sign-in and sign-up through VK ID, Yandex, Google and Telegram, plus attaching them to a signed-in
 * account. `/auth/{provider}/...` accepts only providers that are switched on (`ProviderRegistry`).
 *
 * Matching rules live in `SocialAuthService`; this class only moves the browser through the steps.
 */
final class SocialController
{
    public function __construct(
        private readonly View $view,
        private readonly ProviderRegistry $registry,
        private readonly SocialFlow $flow,
        private readonly SocialAuthService $social,
        private readonly SessionAuth $auth,
        private readonly SessionRegistry $sessions,
        private readonly UserRepository $users,
        private readonly LoginService $login,
        private readonly Validator $validator,
        private readonly FormFlash $flash,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send a guest to the provider's authorization page.
     */
    public function redirect(Request $request, string $provider): Response
    {
        $next = $request->input('next');
        $response = $this->flow->begin($provider, 'login', null, is_string($next) ? $next : null);

        return $response ?? throw new HttpException(404, 'Not found');
    }

    /**
     * Where providers send the visitor back. Handles every outcome, including a denied consent screen.
     */
    public function callback(Request $request, string $provider): Response
    {
        if ($provider === 'telegram') {
            return $this->telegramCallback($request);
        }
        $oauth = $this->registry->get($provider);
        if ($oauth === null) {
            throw new HttpException(404, 'Not found');
        }
        $flow = $this->flow->take($provider, $request->input('state'));
        if ($flow === null) {
            return $this->failure('login', 'Ссылка для входа устарела или уже использована. Нажмите на кнопку входа ещё раз.');
        }
        $intent = $flow['intent'];
        $code = $request->input('code');
        if ($request->input('error') !== null || !is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->failure($intent, 'Вход через ' . $oauth->label() . ' не завершён. Попробуйте ещё раз или выберите другой способ.');
        }
        $query = [];
        foreach (['state', 'device_id'] as $key) {
            $value = $request->input($key);
            if (is_string($value)) {
                $query[$key] = $value;
            }
        }
        try {
            $tokens = $oauth->exchangeCode($code, $flow['verifier'], $this->flow->redirectUri($provider), $query);
            $profile = $oauth->fetchProfile($tokens, $flow['nonce']);
        } catch (OAuthException $e) {
            $this->logger->warning('oauth.failed', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return $this->failure($intent, 'Не удалось войти через ' . $oauth->label() . '. Попробуйте ещё раз или выберите другой способ.');
        }

        return $this->handle($request, $profile, $intent, $flow['user_id'], $flow['next']);
    }

    public function consentShow(): Response
    {
        $pending = $this->flow->pendingAccount(false);
        if ($pending === null) {
            return Response::redirect('/login');
        }

        return $this->view->response('auth/social_consent.twig', [
            'profile' => $pending['profile'],
            'provider_label' => $this->registry->label($pending['profile']->provider),
        ]);
    }

    public function consentStore(Request $request): Response
    {
        $pending = $this->flow->pendingAccount(false);
        if ($pending === null) {
            $this->flash->invalid([], ['form' => 'Время на регистрацию вышло. Войдите ещё раз.']);

            return Response::redirect('/login');
        }
        $name = $request->input('name');
        $name = is_string($name) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') : '';
        $validation = $this->validator->make(['name' => $name], ['name' => 'required|string|min:2|max:100'], ['name' => 'Как вас зовут']);
        $errors = $validation->errors();
        if ($request->input('consent') !== '1') {
            $errors['consent'] = ['Чтобы создать аккаунт, согласитесь на обработку персональных данных.'];
        }
        if ($errors !== []) {
            $this->flash->invalid(['name' => $name, 'consent' => ''], $errors);

            return Response::redirect('/auth/social/consent');
        }
        $this->flow->pendingAccount(true);
        $user = $this->social->createAccount($pending['profile'], $name);
        if ($user === null) {
            $this->flash->invalid([], ['form' => 'Не получилось создать аккаунт. Нажмите на кнопку входа ещё раз.']);

            return Response::redirect('/login');
        }

        return $this->finish($request, $user, $pending['next']);
    }

    /**
     * Telegram Login Widget callback: the browser arrives with the signed profile in the query string.
     */
    private function telegramCallback(Request $request): Response
    {
        $telegram = $this->registry->telegram();
        if ($telegram === null) {
            throw new HttpException(404, 'Not found');
        }
        $flow = $this->flow->take('telegram', $request->input('state'));
        if ($flow === null) {
            return $this->failure('login', 'Ссылка для входа устарела. Обновите страницу и нажмите кнопку Telegram ещё раз.');
        }
        $data = [];
        foreach ($request->all() as $key => $value) {
            if ($key !== 'state') {
                $data[$key] = $value;
            }
        }
        try {
            $profile = $telegram->verify($data);
        } catch (OAuthException $e) {
            $this->logger->warning('oauth.failed', ['provider' => 'telegram', 'reason' => $e->getMessage()]);

            return $this->failure($flow['intent'], 'Не удалось войти через Telegram. Обновите страницу и попробуйте ещё раз.');
        }
        // A signed link stays valid for 24 hours; accept each one only once.
        $hash = $data['hash'] ?? '';
        if (!is_string($hash) || !$this->social->acceptTelegramHash($hash)) {
            return $this->failure($flow['intent'], 'Эта ссылка для входа уже использована. Нажмите на кнопку Telegram ещё раз.');
        }

        return $this->handle($request, $profile, $flow['intent'], $flow['user_id'], $flow['next']);
    }

    private function handle(Request $request, SocialProfile $profile, string $intent, ?int $flowUserId, ?string $next): Response
    {
        $session = $this->flash->session();
        $label = $this->registry->label($profile->provider);
        $current = null;
        if ($intent === 'link') {
            $userId = $session->get('auth.user_id');
            $current = is_int($userId) && $userId === $flowUserId && $this->sessions->activeUserId($session->id()) === $userId ? $this->users->find($userId) : null;
            if ($current === null || $current->isBlocked()) {
                return $this->failure('login', 'Сначала войдите в аккаунт, к которому хотите привязать ' . $label . '.');
            }
        } elseif (is_int($session->get('auth.user_id'))) {
            return Response::redirect('/app');
        }
        $result = $this->social->resolve($profile, $current);

        if ($intent === 'link') {
            [$text, $kind] = match ($result->status) {
                SocialStatus::Linked => [$label . ' привязан: теперь им можно входить в аккаунт.', 'success'],
                SocialStatus::AlreadyLinked => [$label . ' уже привязан к вашему аккаунту.', 'info'],
                SocialStatus::ProviderSlotTaken => ['К аккаунту уже привязан другой профиль ' . $label . '. Сначала отвяжите его.', 'error'],
                default => ['Этот профиль ' . $label . ' уже привязан к другому аккаунту.', 'error'],
            };
            $this->flash->toast($text, $kind);

            return Response::redirect('/account/login-methods');
        }

        switch ($result->status) {
            case SocialStatus::SignedIn:
                $user = $result->user ?? throw new \LogicException('Missing user.');

                return $this->finish($request, $user, $next);
            case SocialStatus::Blocked:
                if ($result->user !== null) {
                    $this->login->journal($result->user->id, 'blocked', $request->ip(), $request->header('user-agent') ?? '');
                }

                $reason = $result->user?->blockReason;

                return $this->failure('login', 'Аккаунт заблокирован.' . ($reason !== null && $reason !== '' ? ' Причина: ' . rtrim($reason, '.') . '.' : '') . ' Напишите в поддержку, и мы разберёмся.');
            case SocialStatus::RegistrationClosed:
                return $this->failure('login', 'Новые аккаунты сейчас создаются только по приглашениям (или регистрация закрыта). Если у вас есть код приглашения, зарегистрируйтесь по почте и позже привяжите этот способ входа.');
            case SocialStatus::EmailExists:
                $this->flow->holdPendingLink($profile);
                $this->flash->invalid([], ['notice' => sprintf(
                    'Аккаунт с почтой %s уже есть. Войдите по почте и паролю, и мы привяжем %s. Если забыли пароль, нажмите «Забыли пароль?».',
                    $profile->email ?? '',
                    $label,
                )]);

                return Response::redirect('/login');
            default:
                $this->flow->holdPendingAccount($profile, $next);

                return Response::redirect('/auth/social/consent');
        }
    }

    /**
     * Sign the user in (asking for the second factor first when they have one).
     */
    private function finish(Request $request, User $user, ?string $next): Response
    {
        $session = $this->flash->session();
        if ($next !== null) {
            $session->set('auth.intended', $next);
        }
        if ($user->hasTwoFactor()) {
            $this->auth->beginTwoFactor($session, $user, false);

            return Response::redirect('/login/2fa');
        }
        $this->auth->signIn($request, $session, $user, false);
        $intended = $session->get('auth.intended');
        $session->forget('auth.intended');

        return Response::redirect(is_string($intended) && Response::isRelativeUrl($intended) ? $intended : '/app');
    }

    private function failure(string $intent, string $message): Response
    {
        if ($intent === 'link') {
            $this->flash->toast($message, 'error');

            return Response::redirect('/account/login-methods');
        }
        $this->flash->invalid([], ['form' => $message]);

        return Response::redirect('/login');
    }
}
