<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * What the editor submits: the common text, files and options, the chosen channels with their optional own values, and the
 * "customise per network" switch. A pure value object; `PostService` checks it against the workspace before saving.
 */
final class PostDraft
{
    /**
     * @param list<string> $mediaIds public ids of library files in order
     * @param list<VariantInput> $variants one per chosen channel
     */
    public function __construct(
        public readonly string $text,
        public readonly array $mediaIds,
        public readonly PostOptions $options,
        public readonly bool $perNetwork,
        public readonly array $variants,
    ) {
    }
}
