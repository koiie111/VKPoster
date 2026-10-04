<?php

declare(strict_types=1);

namespace App\Domain\Post;

use DateTimeImmutable;

/**
 * One variant going out once. `dueAt` is when it was meant to be published (the delay metric is measured from it), `runAt` when
 * the next attempt may start. `errorMessage` is written for the owner; `errorDetail` is technical and for administrators only.
 */
final class Publication
{
    /**
     * @param list<string> $externalIds ids of all messages of the post on the network (an album has several)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $postId,
        public readonly int $variantId,
        public readonly ?int $channelId,
        public readonly PublicationStatus $status,
        public readonly int $attempt,
        public readonly DateTimeImmutable $dueAt,
        public readonly DateTimeImmutable $runAt,
        public readonly ?DateTimeImmutable $enqueuedAt,
        public readonly string $idempotencyKey,
        public readonly ?string $externalPostId,
        public readonly array $externalIds,
        public readonly ?string $externalUrl,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?string $errorDetail,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $deleteAt,
        public readonly ?DateTimeImmutable $deletedAt,
        public readonly ?string $deleteError,
        public readonly bool $pinned,
    ) {
    }
}
