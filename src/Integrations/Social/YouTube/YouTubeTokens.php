<?php

declare(strict_types=1);

namespace App\Integrations\Social\YouTube;

/** Google OAuth tokens for the separately connected YouTube channel. */
final class YouTubeTokens
{
    public function __construct(public readonly string $accessToken, public readonly string $refreshToken, public readonly int $expiresIn, public readonly string $scope)
    {
    }
}
