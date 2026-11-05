<?php

declare(strict_types=1);

namespace App\Domain\Post;

/** Network-specific choices for a short video, stored with the common post or a channel variant. */
final class ShortVideoOptions
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $title = '',
        public readonly string $privacy = '',
        public readonly ?bool $madeForKids = null,
        public readonly ?bool $allowComments = null,
        public readonly ?bool $allowDuet = null,
        public readonly ?bool $allowStitch = null,
        public readonly ?bool $shareToFeed = null,
        public readonly bool $commercialContent = false,
        public readonly bool $musicRightsAccepted = false,
        public readonly ?int $categoryId = null,
        public readonly array $tags = [],
        public readonly ?string $coverMediaId = null,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $tags = [];
        foreach (is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = mb_substr(trim($tag), 0, 30);
            }
        }
        $category = $data['category_id'] ?? null;
        $category = is_numeric($category) && (int) $category > 0 ? (int) $category : null;
        $cover = $data['cover_media_id'] ?? null;

        return new self(
            is_string($data['title'] ?? null) ? mb_substr(trim($data['title']), 0, 255) : '',
            is_string($data['privacy'] ?? null) && preg_match('/^[A-Za-z_]{1,40}$/D', $data['privacy']) === 1 ? $data['privacy'] : '',
            self::nullableFlag($data['made_for_kids'] ?? null),
            self::nullableFlag($data['allow_comments'] ?? null),
            self::nullableFlag($data['allow_duet'] ?? null),
            self::nullableFlag($data['allow_stitch'] ?? null),
            self::nullableFlag($data['share_to_feed'] ?? null),
            self::nullableFlag($data['commercial_content'] ?? null) ?? false,
            self::nullableFlag($data['music_rights_accepted'] ?? null) ?? false,
            $category,
            array_slice(array_values(array_unique($tags)), 0, 30),
            is_string($cover) && preg_match('/^[0-9A-Za-z]{26}$/D', $cover) === 1 ? strtoupper($cover) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'privacy' => $this->privacy,
            'made_for_kids' => $this->madeForKids,
            'allow_comments' => $this->allowComments,
            'allow_duet' => $this->allowDuet,
            'allow_stitch' => $this->allowStitch,
            'share_to_feed' => $this->shareToFeed,
            'commercial_content' => $this->commercialContent,
            'music_rights_accepted' => $this->musicRightsAccepted,
            'category_id' => $this->categoryId,
            'tags' => $this->tags,
            'cover_media_id' => $this->coverMediaId,
        ];
    }

    private static function nullableFlag(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'on', 'true' => true,
            false, 0, '0', 'off', 'false' => false,
            default => null,
        };
    }
}
