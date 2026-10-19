<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Kernel\Queue\AbstractJob;

/** One durable delivery on the existing default Queue, with the standard five-attempt exponential backoff. */
final class DeliverWebhookJob extends AbstractJob
{
    public function __construct(public readonly int $deliveryId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['delivery_id'] ?? 0));
    }

    public function toPayload(): array
    {
        return ['delivery_id' => $this->deliveryId];
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryId);
    }
}
