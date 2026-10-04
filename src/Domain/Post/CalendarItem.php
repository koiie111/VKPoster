<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Integrations\Social\Contracts\Platform;
use DateTimeImmutable;

/**
 * One entry of the calendar: a post going to one channel at one time, with the state of that publication.
 */
final class CalendarItem
{
    public function __construct(
        public readonly string $postId,
        public readonly string $publicationId,
        public readonly Platform $platform,
        public readonly string $channelName,
        public readonly DateTimeImmutable $at,
        public readonly PublicationStatus $status,
        public readonly string $title,
        public readonly ?string $authorName,
        public readonly PostStatus $postStatus,
    ) {
    }
}
