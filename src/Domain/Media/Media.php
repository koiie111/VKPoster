<?php

declare(strict_types=1);

namespace App\Domain\Media;

use DateTimeImmutable;

/**
 * One item of the media library. `storageKey` and `thumbKey` are internal and never reach the browser;
 * `sha256` is the hash of the file as it was uploaded.
 */
final class Media
{
    /**
     * @param array<string, array{key: string, size: int, width: int, height: int, mime: string}> $variants cached renditions by variant name
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly ?int $uploaderId,
        public readonly ?int $folderId,
        public readonly MediaKind $kind,
        public readonly string $originalName,
        public readonly string $storageKey,
        public readonly ?string $thumbKey,
        public readonly string $mime,
        public readonly int $size,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $durationMs,
        public readonly ?string $codec,
        public readonly bool $animated,
        public readonly string $sha256,
        public readonly array $variants,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    public function hasThumb(): bool
    {
        return $this->thumbKey !== null;
    }

    /**
     * Width divided by height, or null when the dimensions are unknown.
     */
    public function ratio(): ?float
    {
        return $this->width !== null && $this->height !== null && $this->height > 0 ? $this->width / $this->height : null;
    }
}
