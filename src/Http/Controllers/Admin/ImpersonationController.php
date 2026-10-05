<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Auth\Impersonation;
use App\Http\FormFlash;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Leaves "signed in as a customer" and returns to the staff account. Open to the impersonated session itself (it is not an admin page).
 */
final class ImpersonationController
{
    public function __construct(private readonly Impersonation $impersonation, private readonly FormFlash $flash)
    {
    }

    public function stop(Request $request): Response
    {
        if (!$this->impersonation->stop($this->flash->session())) {
            return Response::redirect('/app');
        }
        $this->flash->toast('Вы снова в своём аккаунте.');

        return Response::redirect('/admin/users');
    }
}
