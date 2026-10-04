<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

use RuntimeException;

/**
 * A notification that is not authentic (wrong sender address, bad signature) or not readable. Answered with an error and never processed.
 */
final class WebhookRejected extends RuntimeException
{
}
