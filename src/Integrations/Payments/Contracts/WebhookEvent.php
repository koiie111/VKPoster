<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

/**
 * An authentic notification, reduced to what we need. `eventId` identifies the delivery for idempotency (providers do not always send
 * one, so it is built from the type and the payment id); `providerPaymentId` is what to ask the provider about. `amount` (kopecks) is what
 * the notification claims; it is compared with the invoice and with the provider's own answer, never used on its own. `method` is the saved
 * card announced by a signed notification (T-Bank only reports its `RebillId` there).
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $providerPaymentId,
        public readonly ?int $amount = null,
        public readonly ?GatewayMethod $method = null,
    ) {
    }
}
