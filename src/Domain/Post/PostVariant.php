<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Integrations\Social\Contracts\Platform;

/**
 * What one channel receives. `text`, `mediaIds` and `options` are null while the variant follows the post; `resolve()` gives the
 * values that count. `platform` and `channelName` are a snapshot made when the post was saved, so the history stays readable
 * after the channel is disconnected (`channelId` is then null).
 */
final class PostVariant
{
    /**
     * @param list<string>|null $mediaIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly ?int $channelId,
        public readonly Platform $platform,
        public readonly string $channelName,
        public readonly ?string $text,
        public readonly ?array $mediaIds,
        public readonly ?PostOptions $options,
    ) {
    }

    public function resolve(Post $post): ResolvedVariant
    {
        return new ResolvedVariant($this, $this->text ?? $post->baseText, $this->mediaIds ?? $post->mediaIds, $this->options ?? $post->options);
    }

    public function isCustom(): bool
    {
        return $this->text !== null || $this->mediaIds !== null || $this->options !== null;
    }
}
