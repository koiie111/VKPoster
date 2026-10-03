<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Session\Session;

/**
 * Per-request state shared between middleware and view helpers (current request, CSP nonce, session).
 * `Application` resets it at the start of every request, so one instance serves a whole process.
 */
final class RequestContext
{
    private ?Request $request = null;
    private ?Session $session = null;
    private string $nonce = '';

    public function begin(Request $request): void
    {
        $this->request = $request;
        $this->session = null;
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function session(): ?Session
    {
        return $this->session;
    }

    public function setSession(Session $session): void
    {
        $this->session = $session;
    }
}
