<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What applying a provider's report to a payment did.
 */
enum PaymentOutcome: string
{
    /** The invoice was paid and the subscription updated. */
    case Settled = 'settled';

    /** Nothing to do: the payment had been applied before (a repeated notification). */
    case AlreadySettled = 'already_settled';

    /** The payment did not go through; the invoice stays open for another try. */
    case Failed = 'failed';

    /** Still in progress at the provider. */
    case Pending = 'pending';

    /** The amount does not match the invoice: nothing was applied and an alert was raised. */
    case Rejected = 'rejected';

    /** Money arrived for an invoice that was cancelled or already paid: recorded, not applied, needs a manual refund. */
    case Orphaned = 'orphaned';
}
