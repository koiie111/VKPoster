<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Start page of the signed-in application. Placeholder until workspaces and the calendar exist (stages 04 and 07).
 */
final class AppController
{
    public function __construct(private readonly View $view)
    {
    }

    public function dashboard(): Response
    {
        return $this->view->response('app/dashboard.twig');
    }
}
