<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * A successful publication. `externalId` is the id to delete or pin the post by; albums span several messages,
 * all their ids are in `allIds` (the first one is `externalId`).
 */
final class PublishResult
{
    /**
     * @param list<string> $allIds
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $url,
        public readonly array $allIds = [],
    ) {
    }
}
