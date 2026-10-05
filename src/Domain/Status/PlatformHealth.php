<?php

declare(strict_types=1);

namespace App\Domain\Status;

use App\Integrations\Social\Contracts\Platform;

/**
 * How one social network looks to our users right now. `state`: `ok`, `degraded` (publishing attempts keep failing on the platform's side),
 * `maintenance` (the owner switched the platform off or wrote a notice). `notice` is the sentence shown in the banner.
 */
final class PlatformHealth
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const MAINTENANCE = 'maintenance';

    public function __construct(
        public readonly Platform $platform,
        public readonly string $state,
        public readonly ?string $notice,
    ) {
    }

    public function isProblem(): bool
    {
        return $this->state !== self::OK;
    }

    /**
     * @return array{platform: string, state: string, notice: string|null}
     */
    public function toArray(): array
    {
        return ['platform' => $this->platform->value, 'state' => $this->state, 'notice' => $this->notice];
    }
}
