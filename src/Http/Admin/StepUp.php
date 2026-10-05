<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Auth\LoginService;
use App\Domain\User\User;
use App\Http\FormFlash;
use App\Http\Middleware\RequireStaff;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;

/**
 * Re-confirmation of a dangerous action (refund, block, grant, impersonation, price change, reset of someone's 2FA, anonymisation).
 * Being unlocked for the admin area is not enough: the form carries a fresh authenticator (or recovery) code in `confirm_code`, checked
 * here against the signed-in staff member with the same throttle as signing in. The seeded staff account of local development, which has no
 * second factor, is let through (that is the only case; it cannot exist in production).
 */
final class StepUp
{
    public const FIELD = 'confirm_code';

    public function __construct(
        private readonly LoginService $login,
        private readonly RequestContext $context,
        private readonly Config $config,
        private readonly FormFlash $flash,
    ) {
    }

    /**
     * @return 'ok'|'missing'|'invalid'|'throttled'
     */
    public function check(Request $request, User $staff): string
    {
        if (!$staff->hasTwoFactor() && RequireStaff::isDevSession($this->context, $this->config)) {
            return 'ok';
        }
        $code = $request->input(self::FIELD);
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            return 'missing';
        }

        return $this->login->checkSecondFactor($staff, $code, $request->ip(), $request->header('user-agent') ?? '');
    }

    /**
     * The usual way to use the check: null when the action may go ahead, otherwise an error toast and the redirect to send back.
     */
    public function guard(Request $request, User $staff, string $backTo): ?Response
    {
        $outcome = $this->check($request, $staff);
        if ($outcome === 'ok') {
            return null;
        }
        $this->flash->toast(self::message($outcome), 'error');

        return Response::redirect($backTo);
    }

    /**
     * What to tell the staff member when the check did not pass.
     */
    public static function message(string $outcome): string
    {
        return match ($outcome) {
            'missing' => 'Введите код из приложения-аутентификатора: действие необратимо, мы просим подтвердить его ещё раз.',
            'throttled' => 'Слишком много неверных кодов. Подождите 10 минут и повторите.',
            default => 'Код не подошёл. Проверьте код в приложении или введите резервный код.',
        };
    }
}
