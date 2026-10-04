<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/**
 * Builds the Content-Security-Policy header value. Scripts run only from our own origin or with the
 * per-request nonce; no inline handlers, no plugins, no framing, no `<base>` hijacking.
 */
final class Csp
{
    /**
     * @param array<string, list<string>> $extra additional sources per directive for this response only
     *                                           (`['script-src' => ['https://telegram.org']]`); a directive
     *                                           that is not in the base policy is added
     */
    public function header(string $nonce, array $extra = []): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-" . $nonce . "'"],
            'img-src' => ["'self'", 'data:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
        ];
        foreach ($extra as $directive => $sources) {
            $directives[$directive] = array_values(array_unique([...($directives[$directive] ?? []), ...$sources]));
        }

        return implode('; ', array_map(static fn (string $name, array $sources): string => $name . ' ' . implode(' ', $sources), array_keys($directives), $directives));
    }
}
