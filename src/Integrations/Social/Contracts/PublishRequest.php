<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use App\Domain\Post\PostKind;
use App\Domain\Post\ShortVideoOptions;

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
        /** Kind of post for networks that have several (Instagram: `feed`, `reel`, `story`); empty = decided from the files. */
        public readonly string $mode = '',
        public readonly PostKind $kind = PostKind::Regular,
        public readonly ?ShortVideoOptions $shortVideo = null,
        public readonly ?string $coverUrl = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self(
            $this->text,
            $this->media,
            $this->buttons,
            $this->poll,
            $this->silent,
            $this->format,
            $this->disablePreview,
            $this->mode,
            $this->kind,
            $this->shortVideo,
            $this->coverUrl,
            $key
        );
    }
}
