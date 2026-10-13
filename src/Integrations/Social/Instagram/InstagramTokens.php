<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use SensitiveParameter;

/**
 * What Instagram hands out after a sign-in or a refresh. There is no refresh token: the long-lived access token (about 60 days) is
 * itself extended before it expires.
 */
final class InstagramTokens
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly string $userId,
        public readonly string $scope,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'scope' => $this->scope, 'token' => '***'];
    }
}
