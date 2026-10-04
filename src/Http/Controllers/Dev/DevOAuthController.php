<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Integrations\OAuth\FakeProvider;
use App\Integrations\OAuth\ProviderRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The "authorization page" of the fake sign-in provider (local development only). A developer types the
 * profile the provider should report; the approval redirects back to the real callback with a fake `code`.
 * Answers 404 unless the fake provider is enabled (DEV_OAUTH_FAKE=1 outside production).
 */
final class DevOAuthController
{
    public function __construct(private readonly View $view, private readonly ProviderRegistry $registry)
    {
    }

    public function show(Request $request): Response
    {
        $this->assertEnabled();
        $state = $request->input('state');
        $challenge = $request->input('challenge');
        $nonce = $request->input('nonce');
        if (!is_string($state) || !is_string($challenge) || !is_string($nonce)) {
            throw new HttpException(404, 'Not found');
        }

        return $this->view->response('dev/oauth_fake.twig', [
            'state' => $state,
            'challenge' => $challenge,
            'nonce' => $nonce,
            'suggested_id' => 'fake-' . bin2hex(random_bytes(3)),
        ]);
    }

    public function approve(Request $request): Response
    {
        $this->assertEnabled();
        $state = $request->input('state');
        $challenge = $request->input('challenge');
        $nonce = $request->input('nonce');
        $id = $request->input('id');
        if (!is_string($state) || !is_string($challenge) || !is_string($nonce) || !is_string($id) || $id === '') {
            throw new HttpException(404, 'Not found');
        }
        $email = $request->input('email');
        $name = $request->input('name');
        $code = FakeProvider::makeCode([
            'id' => $id,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'email_verified' => $request->input('email_verified') === '1',
            'name' => is_string($name) ? $name : '',
            'avatar' => null,
        ], $challenge, $nonce);

        return Response::redirect('/auth/fake/callback?' . http_build_query(['code' => $code, 'state' => $state], '', '&', PHP_QUERY_RFC3986));
    }

    private function assertEnabled(): void
    {
        if ($this->registry->get('fake') === null) {
            throw new HttpException(404, 'Not found');
        }
    }
}
