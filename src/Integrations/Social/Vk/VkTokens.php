<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use SensitiveParameter;

/**
 * What VK ID hands out after a sign-in or a refresh. The refresh token is single use: every refresh returns a new pair and the old
 * one stops working, so a pair must be stored the moment it arrives.
 */
final class VkTokens
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $accessToken,
        #[SensitiveParameter]
        public readonly string $refreshToken,
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
        return ['userId' => $this->userId, 'scope' => $this->scope, 'tokens' => '***'];
    }
}
