<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use RuntimeException;

/**
 * A billing action that cannot be done (an unknown plan, a plan already paid for, a provider that is switched off). The message is
 * Russian and safe to show to the customer.
 */
final class BillingException extends RuntimeException
{
}
