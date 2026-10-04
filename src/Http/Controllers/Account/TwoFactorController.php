<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\AuthMailer;
use App\Domain\Auth\PasswordService;
use App\Domain\Auth\QrCode;
use App\Domain\Auth\TwoFactorService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\FormFlash;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\RateLimiter;
use App\Kernel\View\View;
use LogicException;

/**
 * Turning two-factor authentication on and off and managing recovery codes.
 * Recovery codes are rendered straight in the POST response and never stored in the session.
 */
final class TwoFactorController
{
    public function __construct(
        private readonly View $view,
        private readonly TwoFactorService $twoFactor,
        private readonly PasswordService $passwords,
        private readonly UserRepository $users,
        private readonly QrCode $qr,
        private readonly Crypto $crypto,
        private readonly AuthMailer $mailer,
        private readonly AuditLog $audit,
        private readonly RateLimiter $limiter,
        private readonly FormFlash $flash,
        private readonly Config $config,
    ) {
    }

    public function start(Request $request): Response
    {
        $user = $this->user($request);
        if ($user->hasTwoFactor()) {
            return Response::redirect('/account/security#two-factor');
        }
        $this->twoFactor->beginSetup($user);

        return Response::redirect('/account/2fa/setup');
    }

    public function setup(Request $request): Response
    {
        $secret = $this->pendingSecret($this->fresh($request));
        if ($secret === null) {
            return Response::redirect('/account/security#two-factor');
        }

        return $this->view->response('account/two_factor_setup.twig', [
            'user' => $this->user($request),
            'secret_groups' => str_split($secret, 4),
        ]);
    }

    public function qr(Request $request): Response
    {
        $user = $this->fresh($request);
        $secret = $this->pendingSecret($user);
        if ($secret === null) {
            return Response::text('Not found', 404);
        }
        $account = $user->email ?? $user->name;
        $issuer = $this->config->string('app.name');
        if ($secret === '' || $account === '' || $issuer === '') {
            return Response::text('Not found', 404);
        }
        $uri = $this->twoFactor->provisioningUri($secret, $account, $issuer);

        return (new Response(200, $this->qr->svg($uri)))
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function confirm(Request $request): Response
    {
        $user = $this->fresh($request);
        $code = $this->string($request->input('code'));
        if (!$this->limiter->attempt('2fa-setup:' . $user->id, 10, 600)->allowed) {
            $this->flash->invalid([], ['code' => 'Слишком много попыток. Подождите 10 минут и повторите.']);

            return Response::redirect('/account/2fa/setup');
        }
        $codes = $this->twoFactor->confirmSetup($user, $code);
        if ($codes === null) {
            $this->flash->invalid([], ['code' => 'Код не подошёл. Проверьте, что время на телефоне точное, и введите свежий код.']);

            return Response::redirect('/account/2fa/setup');
        }
        $this->audit->record('auth.2fa.enabled', $user->id, 'user', (string) $user->id);
        $this->mailer->twoFactorChanged($user, true);

        return $this->view->response('account/recovery_codes.twig', ['codes' => $codes, 'just_enabled' => true]);
    }

    public function disable(Request $request): Response
    {
        $user = $this->fresh($request);
        $password = $this->string($request->input('disable_password'));
        $code = $this->string($request->input('disable_code'));
        $errors = [];
        if ($password === '') {
            $errors['disable_password'] = ['Введите пароль.'];
        }
        if ($code === '') {
            $errors['disable_code'] = ['Введите код из приложения или резервный код.'];
        }
        if ($errors === []) {
            $check = $this->passwords->confirmPassword($user, $password);
            if ($check !== 'ok') {
                $errors['disable_password'] = [$check === 'throttled' ? 'Слишком много попыток. Подождите 10 минут и повторите.' : 'Пароль указан неверно.'];
            } elseif (!$this->limiter->attempt('2fa:' . $user->id, 5, 600)->allowed || !$this->twoFactor->verifyAny($user, $code)) {
                $errors['disable_code'] = ['Код не подошёл. Проверьте код в приложении или введите резервный код.'];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid([], $errors);

            return Response::redirect('/account/security#two-factor');
        }
        $this->twoFactor->disable($user);
        $this->audit->record('auth.2fa.disabled', $user->id, 'user', (string) $user->id);
        $this->mailer->twoFactorChanged($user, false);
        $this->flash->toast('Двухфакторная защита отключена.');

        return Response::redirect('/account/security');
    }

    public function regenerateCodes(Request $request): Response
    {
        $user = $this->fresh($request);
        $check = $user->hasTwoFactor() ? $this->passwords->confirmPassword($user, $this->string($request->input('codes_password'))) : 'wrong_password';
        if ($check !== 'ok') {
            $this->flash->invalid([], ['codes_password' => [$check === 'throttled' ? 'Слишком много попыток. Подождите 10 минут и повторите.' : 'Пароль указан неверно.']]);

            return Response::redirect('/account/security#two-factor');
        }
        $codes = $this->twoFactor->generateRecoveryCodes($user->id);
        $this->audit->record('auth.2fa.recovery_regenerated', $user->id, 'user', (string) $user->id);

        return $this->view->response('account/recovery_codes.twig', ['codes' => $codes, 'just_enabled' => false]);
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');

        return $user instanceof User ? $user : throw new LogicException('Authenticate middleware is missing.');
    }

    /**
     * The user row as it is now (the one on the request may predate a change made earlier in the same request).
     */
    private function fresh(Request $request): User
    {
        $user = $this->user($request);

        return $this->users->find($user->id) ?? $user;
    }

    private function pendingSecret(User $user): ?string
    {
        if ($user->hasTwoFactor() || $user->totpSecretEnc === null) {
            return null;
        }

        return $this->crypto->decrypt($user->totpSecretEnc);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
