<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Queue\AbstractJob;

/** Recheck one accepted short video after the network's asynchronous processing delay. */
final class VideoProcessingJob extends AbstractJob
{
    public function __construct(public readonly int $publicationId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['publication_id'] ?? null) ? $payload['publication_id'] : 0);
    }

    public function toPayload(): array
    {
        return ['publication_id' => $this->publicationId];
    }

    public function handle(Publisher $publisher): void
    {
        $publisher->pollVideo($this->publicationId);
    }
}
