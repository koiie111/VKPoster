<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\PasswordService;
use App\Domain\Auth\TwoFactorService;
use App\Domain\Auth\WebAuthnService;
use App\Domain\User\User;
use App\Http\FormFlash;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Security\RateLimiter;

/** Existing authenticated/CSRF account boundary, fresh proof and fail-closed throttling for hardware enrollment/revocation. */
final class WebAuthnController
{
    public function __construct(private readonly WebAuthnService $keys, private readonly PasswordService $passwords, private readonly TwoFactorService $twoFactor, private readonly RateLimiter $limiter, private readonly FormFlash $flash, private readonly AuditLog $audit)
    {
    }

    public function begin(Request $request): Response
    {
        $user = $this->proof($request);
        return Response::json($this->keys->options($user, $this->flash->session()->id(), true));
    }

    public function register(Request $request): Response
    {
        $user = $this->user($request);
        $this->limit($user);
        if (strlen($request->rawBody) > 131072) {
            throw new HttpException(413, 'Ответ ключа слишком большой.');
        }
        $payload = $request->json();
        $label = is_string($payload['label'] ?? null) ? trim($payload['label']) : '';
        $this->keys->register($user, $this->flash->session()->id(), $payload, $label);
        $this->audit->record('auth.webauthn.enrolled', $user->id, 'user', (string) $user->id);
        return Response::json(['ok' => true]);
    }

    public function revoke(Request $request): Response
    {
        $user = $this->proof($request);
        $id = $request->json()['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new HttpException(422, 'Некорректный ключ.');
        }
        $this->keys->revoke($user, $id);
        $this->audit->record('auth.webauthn.revoked', $user->id, 'user', (string) $user->id);
        return Response::json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->attribute('user');
        return $user instanceof User ? $user : throw new HttpException(401);
    }

    private function proof(Request $request): User
    {
        $user = $this->user($request);
        $this->limit($user);
        if (strlen($request->rawBody) > 4096) {
            throw new HttpException(413, 'Запрос слишком большой.');
        }
        $data = $request->json();
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $code = is_string($data['code'] ?? null) ? $data['code'] : '';
        if (!$user->hasTwoFactor() || ($user->passwordHash !== null && $this->passwords->confirmPassword($user, $password) !== 'ok') || !$this->twoFactor->verifyAny($user, $code)) {
            throw new HttpException(422, 'Проверьте пароль и код двухфакторной защиты.');
        }
        return $user;
    }

    private function limit(User $user): void
    {
        try {
            $allowed = $this->limiter->attempt('webauthn-manage:' . $user->id, 10, 600, true)->allowed;
        } catch (\Throwable) {
            throw new HttpException(503, 'Проверка безопасности временно недоступна.');
        }
        if (!$allowed) {
            throw new HttpException(429, 'Слишком много попыток. Повторите через 10 минут.');
        }
    }
}
