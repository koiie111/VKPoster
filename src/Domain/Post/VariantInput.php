<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * The editor's choice for one channel. Null text, files or options mean "same as the post".
 */
final class VariantInput
{
    /**
     * @param list<string>|null $mediaIds
     */
    public function __construct(
        public readonly string $channelId,
        public readonly ?string $text = null,
        public readonly ?array $mediaIds = null,
        public readonly ?PostOptions $options = null,
    ) {
    }
}
