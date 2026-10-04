<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * Result of a channel health check. `ok` = can publish now. `transient` = we could not find out (network, rate limit),
 * so the channel keeps its current status. `revoked` marks a broken channel the owner has to reconnect from scratch.
 */
final class HealthStatus
{
    /**
     * @param array<string, bool> $rights what the account may do in the channel (post, edit, delete, pin)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly bool $transient = false,
        public readonly array $rights = [],
        public readonly ?string $title = null,
        public readonly bool $revoked = false,
    ) {
    }

    /**
     * @param array<string, bool> $rights
     */
    public static function ok(array $rights = [], ?string $title = null): self
    {
        return new self(true, '', false, $rights, $title);
    }

    /**
     * @param bool $revoked the account was removed from the channel (or the channel is gone), as opposed to merely lacking a right
     */
    public static function broken(string $message, bool $revoked = false): self
    {
        return new self(false, $message, false, [], null, $revoked);
    }

    public static function unknown(string $message): self
    {
        return new self(false, $message, true);
    }
}
