<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * A variant with its inherited values filled in: the text, files and options the channel will really get.
 */
final class ResolvedVariant
{
    /**
     * @param list<string> $mediaIds
     */
    public function __construct(
        public readonly PostVariant $variant,
        public readonly string $text,
        public readonly array $mediaIds,
        public readonly PostOptions $options,
    ) {
    }
}
