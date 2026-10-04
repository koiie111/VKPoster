<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use RuntimeException;

/**
 * Any failure of a platform call, already classified. The message is safe to log and to show to the user: it never
 * contains tokens or request URLs. `userMessage` is the Russian explanation for the UI.
 */
final class PlatformError extends RuntimeException
{
    public function __construct(
        public readonly ErrorKind $kind,
        string $message,
        public readonly string $userMessage = '',
        public readonly ?int $retryAfter = null,
        public readonly ?int $platformCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether the failure says something about the channel itself (so the channel is marked broken), as opposed to a
     * passing problem of the network or the platform.
     */
    public function concernsChannel(): bool
    {
        return $this->kind === ErrorKind::Auth || $this->kind === ErrorKind::Permanent;
    }

    public function forUser(): string
    {
        return $this->userMessage !== '' ? $this->userMessage : 'Не удалось связаться с соцсетью. Попробуйте позже.';
    }
}
