<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * Where a post is in its life. Before scheduling it is a `draft`; afterwards the status is derived from its publications
 * (`PostStatusAggregator`), never set by hand.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Scheduled => 'Запланирован',
            self::Publishing => 'Публикуется',
            self::Published => 'Опубликован',
            self::PartiallyFailed => 'Опубликован частично',
            self::Failed => 'Не удалось',
            self::Cancelled => 'Отменён',
        };
    }

    /**
     * Key understood by the `status_badge` component.
     */
    public function badge(): string
    {
        return match ($this) {
            self::PartiallyFailed => 'partial',
            self::Cancelled => 'canceled',
            default => $this->value,
        };
    }

    /**
     * Whether the post may still be edited as a plan: nothing has gone out and nothing is going out right now.
     */
    public function isEditablePlan(): bool
    {
        return $this === self::Draft || $this === self::Scheduled || $this === self::Cancelled || $this === self::Failed;
    }
}
