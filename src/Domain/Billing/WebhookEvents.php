<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Integrations\Payments\Contracts\WebhookEvent;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * The journal of provider notifications, and the guard against processing one twice: `UNIQUE(provider, event_id)` lets only the first
 * delivery through.
 */
final class WebhookEvents
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param string|null $rawBody the notification as received; it is stored with personal and secret fields masked
     * @return bool false when this delivery was already seen
     */
    public function begin(WebhookEvent $event, ?string $rawBody = null): bool
    {
        try {
            $this->db->table('webhook_events')->insert([
                'provider' => $event->provider,
                'event_id' => $event->eventId,
                'type' => mb_substr($event->type, 0, 64),
                'payment_ref' => $event->providerPaymentId === null ? null : mb_substr($event->providerPaymentId, 0, 128),
                'outcome' => 'received',
                'payload' => $rawBody === null || $rawBody === '' ? null : WebhookMask::apply($rawBody),
                'received_at' => DbTime::format($this->clock->now()),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function finish(WebhookEvent $event, string $outcome, ?string $detail = null): void
    {
        $this->db->table('webhook_events')->where('provider', '=', $event->provider)->where('event_id', '=', $event->eventId)->update([
            'outcome' => mb_substr($outcome, 0, 16),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 255),
        ]);
    }

    /**
     * Forget a delivery whose processing failed, so the provider's retry is handled instead of being taken for a repeat.
     */
    public function forget(WebhookEvent $event): void
    {
        $this->db->table('webhook_events')->where('provider', '=', $event->provider)->where('event_id', '=', $event->eventId)->delete();
    }

    public function prune(int $days = 90): int
    {
        return $this->db->table('webhook_events')->where('received_at', '<', DbTime::format($this->clock->now()->modify(sprintf('-%d days', $days))))->delete();
    }
}
