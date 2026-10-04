<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Queue\AbstractJob;

/**
 * Publishes one publication (see `Publisher`). It sits in its own queue that workers read first, so mail and health checks never
 * make a post late. Safe to run twice: the claim lets only one run send anything.
 */
final class PublishJob extends AbstractJob
{
    public const QUEUE = 'publish';

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
        $publisher->run($this->publicationId);
    }
}
