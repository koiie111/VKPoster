<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/**
 * Builds the Content-Security-Policy header value. Scripts run only from our own origin or with the
 * per-request nonce; no inline handlers, no plugins, no framing, no `<base>` hijacking.
 */
final class Csp
{
    public function header(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "img-src 'self' data:",
            "object-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]);
    }
}
