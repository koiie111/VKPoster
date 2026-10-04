<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * Derives the status of a post from its publications (master plan §4.4): all sent = published, some sent = partially failed,
 * none sent = failed; anything still queued or sending keeps the post scheduled or publishing.
 */
final class PostStatusAggregator
{
    /**
     * @param list<Publication> $publications
     */
    public static function aggregate(PostStatus $current, array $publications): PostStatus
    {
        $live = array_values(array_filter($publications, static fn (Publication $p): bool => $p->status !== PublicationStatus::Cancelled));
        if ($live === []) {
            // Nothing is going out: a draft stays a draft, anything that had a plan is cancelled.
            return $current === PostStatus::Draft || $publications === [] && $current !== PostStatus::Cancelled ? PostStatus::Draft : PostStatus::Cancelled;
        }
        $sent = 0;
        $busy = false;
        $started = false;
        foreach ($live as $publication) {
            if ($publication->status === PublicationStatus::Sending) {
                $busy = true;
            }
            if ($publication->status === PublicationStatus::Sent) {
                ++$sent;
            }
            if ($publication->status !== PublicationStatus::Queued || $publication->attempt > 0) {
                $started = true;
            }
        }
        if ($busy) {
            return PostStatus::Publishing;
        }
        foreach ($live as $publication) {
            if ($publication->status === PublicationStatus::Queued) {
                return $started ? PostStatus::Publishing : PostStatus::Scheduled;
            }
        }
        if ($sent === count($live)) {
            return PostStatus::Published;
        }

        return $sent > 0 ? PostStatus::PartiallyFailed : PostStatus::Failed;
    }
}
