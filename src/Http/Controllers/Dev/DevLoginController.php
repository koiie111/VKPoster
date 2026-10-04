<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domain\User\UserRepository;
use App\Http\Auth\SessionAuth;
use App\Http\FormFlash;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * `/dev/login-as/{id}`: sign in as any user without a password, for agents and smoke tests.
 * Works only when APP_ENV is `local` (or `testing`) and DEV_LOGIN is not switched off; in production
 * the route answers 404 and the application refuses to boot with a DEV_* flag enabled.
 */
final class DevLoginController
{
    public function __construct(
        private readonly Config $config,
        private readonly UserRepository $users,
        private readonly SessionAuth $auth,
        private readonly FormFlash $flash,
    ) {
    }

    public function loginAs(Request $request, string $id): Response
    {
        $env = $this->config->string('app.env');
        if (($env !== 'local' && $env !== 'testing') || !$this->config->bool('auth.dev_login')) {
            throw new HttpException(404, 'Not found');
        }
        $user = $this->users->find((int) $id);
        if ($user === null) {
            throw new HttpException(404, 'Not found');
        }
        $this->auth->signIn($request, $this->flash->session(), $user, false);

        return Response::redirect('/app');
    }
}
