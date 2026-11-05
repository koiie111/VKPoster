<?php

declare(strict_types=1);

namespace App\Integrations\Social\TikTok;

/** A rotated OAuth token pair. The refresh token must replace the previous one atomically. */
final class TikTokTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $openId,
        public readonly string $scope,
    ) {
    }
}
