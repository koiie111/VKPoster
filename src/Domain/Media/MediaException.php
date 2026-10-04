<?php

declare(strict_types=1);

namespace App\Domain\Media;

use RuntimeException;

/**
 * A media operation was refused for a reason the user can fix. The message is written for the user
 * (Russian, no technical details) and may be shown as is.
 */
final class MediaException extends RuntimeException
{
    /**
     * @param bool $planLimit the plan's library size is used up, so the page can offer a bigger plan
     */
    public function __construct(string $message, public readonly bool $planLimit = false)
    {
        parent::__construct($message);
    }
}
