<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

/**
 * PKCE (RFC 7636) pair with the S256 method: the verifier stays in our session, only its hash travels
 * in the authorization URL, so a stolen `code` is useless without the verifier.
 */
final class Pkce
{
    public function __construct(public readonly string $verifier, public readonly string $challenge)
    {
    }

    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(48));

        return new self($verifier, self::challengeFor($verifier));
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
