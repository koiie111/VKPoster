<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * What to publish to one channel. Stage 07 builds it from a post variant; adapters never see posts or the database.
 */
final class PublishRequest
{
    /**
     * @param list<PublishMedia> $media
     * @param list<array{text: string, url: string}> $buttons link buttons under the post
     * @param array{question: string, options: list<string>, anonymous: bool, multiple: bool}|null $poll
     * @param string|null $format null = plain text, `html` = the platform's HTML subset
     */
    public function __construct(
        public readonly string $text,
        public readonly array $media = [],
        public readonly array $buttons = [],
        public readonly ?array $poll = null,
        public readonly bool $silent = false,
        public readonly ?string $format = null,
        public readonly bool $disablePreview = false,
    ) {
    }
}
