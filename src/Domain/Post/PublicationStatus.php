<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * State of one publication (one post going to one channel). The allowed moves are the whole safety net against duplicate posts:
 *
 * - `queued → sending` only by the worker that wins the claim; `sending → queued` is a scheduled retry after a temporary failure;
 * - `unknown` (the request may have reached the network) never goes on by itself: only a person can retry, confirm or drop it;
 * - `sent` and `cancelled` are final.
 */
enum PublicationStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Queued => [self::Sending, self::Cancelled],
            self::Sending => [self::Sent, self::Queued, self::Failed, self::Unknown],
            self::Failed => [self::Queued],
            self::Unknown => [self::Queued, self::Sent, self::Cancelled],
            self::Sent, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'В очереди',
            self::Sending => 'Отправляется',
            self::Sent => 'Опубликовано',
            self::Failed => 'Не удалось',
            self::Unknown => 'Нужно проверить',
            self::Cancelled => 'Отменено',
        };
    }

    /**
     * Key understood by the `status_badge` component.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'scheduled',
            self::Sending => 'publishing',
            self::Sent => 'published',
            self::Failed => 'failed',
            self::Unknown => 'unknown',
            self::Cancelled => 'canceled',
        };
    }
}
