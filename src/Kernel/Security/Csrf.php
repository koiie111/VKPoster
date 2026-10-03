<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Http\Request;
use App\Kernel\Session\Session;

/**
 * Synchronizer-token CSRF protection. The token lives in the session; forms send it as `_token`,
 * htmx/fetch as `X-CSRF-Token`. Requests that carry `Origin` or `Sec-Fetch-Site` must also be same-origin.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';

    public function token(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * True when the request passes both the origin check and the token check.
     */
    public function isValid(Request $request, Session $session): bool
    {
        return $this->isSameOrigin($request) && $this->tokenMatches($request, $session);
    }

    public function tokenMatches(Request $request, Session $session): bool
    {
        $expected = $session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->body['_token'] ?? $request->header('x-csrf-token');

        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    public function isSameOrigin(Request $request): bool
    {
        $site = $request->header('sec-fetch-site');
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }
        $origin = $request->header('origin');
        if ($origin === null) {
            return true;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false; // "null" origin and malformed values
        }
        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $host === $request->host();
    }
}
