<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use RuntimeException;

/**
 * An action the current plan does not allow (limit reached, feature not included). The message is Russian and safe to show; `limit`
 * names what ran out (`channels`, `posts`, `members`, `workspaces`, `storage` or `feature:<name>`), so a page can offer the right way out.
 * Services throw it themselves, so a limit holds whether the request came from the UI, a webhook or the API.
 */
final class PlanLimitException extends RuntimeException
{
    public function __construct(public readonly string $limit, string $message)
    {
        parent::__construct($message);
    }
}
