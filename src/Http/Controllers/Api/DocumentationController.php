<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Self-hosted Redoc documentation; the isolated page contains no user-controlled HTML or credentials. */
final class DocumentationController
{
    public function __construct(private readonly View $view)
    {
    }

    public function show(): Response
    {
        return $this->view->response('api/docs.twig')->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }
}
