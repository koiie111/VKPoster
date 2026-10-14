<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use RuntimeException;

/**
 * A rule, queue or feed that cannot be saved or used. The message is for the person, in Russian; `forbidden` marks a missing
 * permission (the page answers 403) and `planLimit` a limit of the plan (the page offers the plans).
 */
final class AutomationException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $forbidden = false, public readonly bool $planLimit = false)
    {
        parent::__construct($message);
    }
}
