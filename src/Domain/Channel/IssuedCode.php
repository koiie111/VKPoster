<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use DateTimeImmutable;

/**
 * A freshly made connect code. `code` is the only time the plain value exists on our side (the table keeps its hash).
 */
final class IssuedCode
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $code,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * `ABCDE-FGHJK`, easier to read aloud and to copy than ten letters in a row.
     */
    public function pretty(): string
    {
        return ConnectCodes::pretty($this->code);
    }
}
