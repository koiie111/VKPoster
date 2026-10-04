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
    private ?object $user = null;
    private ?object $workspace = null;

    /** @var array<string, list<string>> */
    private array $cspExtras = [];

    public function begin(Request $request): void
    {
        $this->request = $request;
        $this->session = null;
        $this->user = null;
        $this->workspace = null;
        $this->cspExtras = [];
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

    /**
     * Signed-in user of this request (set by the `Authenticate` middleware), for templates.
     */
    public function user(): ?object
    {
        return $this->user;
    }

    public function setUser(?object $user): void
    {
        $this->user = $user;
    }

    /**
     * Workspace context of this request (set by the `ResolveWorkspace` middleware), for templates and policies.
     */
    public function workspace(): ?object
    {
        return $this->workspace;
    }

    public function setWorkspace(?object $workspace): void
    {
        $this->workspace = $workspace;
    }

    /**
     * Allow one more source in a Content-Security-Policy directive for this response only (for a
     * third-party widget on a single page). Pass exact origins, never wildcards.
     */
    public function allowCsp(string $directive, string $source): void
    {
        $this->cspExtras[$directive][] = $source;
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspExtras(): array
    {
        return $this->cspExtras;
    }
}
