<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Queue\AbstractJob;

/**
 * Deletes a published post from its network when its "delete after" time has come (see `PublicationDeleter`).
 */
final class DeletePublicationJob extends AbstractJob
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

    public function handle(PublicationDeleter $deleter): void
    {
        $deleter->run($this->publicationId);
    }
}
