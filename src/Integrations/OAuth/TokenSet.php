<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

/**
 * What a token endpoint returned. Sign-in uses the tokens once to read the profile and then drops them
 * (posting rights are requested separately when a channel is connected, stage 06+).
 */
final class TokenSet
{
    /**
     * @param array<string, mixed> $extra other fields of the reply (for example VK's `user_id`)
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $idToken = null,
        public readonly array $extra = [],
    ) {
    }
}
