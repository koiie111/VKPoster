<?php

declare(strict_types=1);

namespace App\Domain\Post;

use DateTimeImmutable;

/**
 * A post of a workspace: the common text, files and options, and when it goes out. What each channel actually receives is the
 * `PostVariant`, which inherits from the post until it is changed. `publicId` (ULID) is the only id that may appear in URLs.
 */
final class Post
{
    /**
     * @param list<string> $mediaIds public ids of library files, in display order
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly ?int $authorId,
        public readonly PostStatus $status,
        public readonly string $baseText,
        public readonly array $mediaIds,
        public readonly PostOptions $options,
        public readonly bool $perNetwork,
        public readonly ?DateTimeImmutable $scheduledAt,
        public readonly string $timezone,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * A short line to recognise the post by in lists and the calendar: the first non-empty line of the text, without markup.
     */
    public function title(int $limit = 80): string
    {
        $visible = TextFormatter::visible($this->baseText);
        $lines = preg_split('/\R/u', $visible);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strlen($line) > $limit ? rtrim(mb_substr($line, 0, $limit - 1)) . '…' : $line;
            }
        }

        return $this->mediaIds !== [] ? 'Пост без текста' : 'Пустой пост';
    }
}
