<?php

declare(strict_types=1);

namespace App\Domain\Post;

use RuntimeException;

/**
 * A post action that cannot be done, with a message for the person (Russian, safe to show) and optionally the problems
 * found in each channel. `forbidden` marks refusals that are about permissions rather than about the content.
 */
final class PostException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $channelProblems channel public id (or `*` for the whole post) => problems
     * @param bool $retryable the cause is passing (storage hiccup), so the pipeline may try again later
     */
    public function __construct(string $message, public readonly array $channelProblems = [], public readonly bool $forbidden = false, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
