<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Placeholder landing page until stage 11.
 */
final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(): Response
    {
        return $this->view->response('home.twig');
    }
}
