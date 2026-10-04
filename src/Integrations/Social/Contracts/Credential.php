<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use SensitiveParameter;

/**
 * Decrypted secret an adapter needs for one call. Lives only in memory for the duration of the call; `__debugInfo`
 * hides the secret from `var_dump` and error dumps.
 */
final class Credential
{
    public function __construct(
        public readonly Platform $platform,
        #[SensitiveParameter]
        public readonly string $secret,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['platform' => $this->platform->value, 'secret' => '***'];
    }
}
