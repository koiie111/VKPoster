<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use RuntimeException;

/**
 * Controller with throw-away routes used only by feature tests.
 */
final class TestController
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function echo(Request $request): Response
    {
        return Response::text('posted:' . (string) $request->input('v', ''));
    }

    public function boom(): Response
    {
        throw new RuntimeException('secret internal detail /var/www/html/src/Secret.php');
    }

    public function login(): Response
    {
        $session = $this->context->session();
        $session?->regenerate();
        $session?->set('auth.user_id', 42);

        return Response::text('logged in');
    }

    public function whoami(Request $request): Response
    {
        return Response::text('user:' . (string) $request->attribute('user_id'));
    }

    public function item(string $id): Response
    {
        return Response::text('item:' . $id);
    }

    public function flash(): Response
    {
        $this->context->session()?->flash('notice', 'saved');

        return Response::redirect('/_t/flash/read');
    }

    public function readFlash(): Response
    {
        return Response::text('flash:' . (string) $this->context->session()?->getFlash('notice', 'none'));
    }

    /**
     * @return array{ok: true}
     */
    public function data(): array
    {
        return ['ok' => true];
    }
}
