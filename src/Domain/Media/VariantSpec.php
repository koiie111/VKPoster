<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Which rendition of a picture is wanted: the crop shape, whether the watermark is stamped on it, the longest
 * side and the file size ceiling. The slug (`1x1`, `4x5-wm`, ...) is what appears in `/media/{id}/{slug}`.
 */
final class VariantSpec
{
    /** Slug of a crop => width / height (null keeps the original shape). */
    public const RATIOS = ['original' => null, '1x1' => 1.0, '4x5' => 0.8, '191x100' => 1.91, '9x16' => 0.5625];

    public const DEFAULT_MAX_EDGE = 2560;

    /**
     * @param string $watermarkId public id of a specific watermark, or null for the workspace default (when `$watermark` is on)
     */
    public function __construct(
        public readonly string $crop = 'original',
        public readonly bool $watermark = false,
        public readonly ?string $watermarkId = null,
        public readonly int $maxEdge = self::DEFAULT_MAX_EDGE,
        public readonly ?int $maxBytes = null,
    ) {
        if (!array_key_exists($crop, self::RATIOS)) {
            throw new \InvalidArgumentException('Unknown crop: ' . $crop);
        }
    }

    /**
     * Parse a URL slug such as `1x1` or `original-wm`; null when it is not a known variant.
     */
    public static function fromSlug(string $slug): ?self
    {
        if (preg_match('/^(original|1x1|4x5|191x100|9x16)(-wm)?$/', $slug, $m) !== 1) {
            return null;
        }

        return new self($m[1], ($m[2] ?? '') === '-wm');
    }

    public function slug(): string
    {
        return $this->crop . ($this->watermark ? '-wm' : '');
    }

    public function ratio(): ?float
    {
        return self::RATIOS[$this->crop];
    }

    /**
     * True when the result differs from the stored original in any way.
     */
    public function transforms(): bool
    {
        return $this->crop !== 'original' || $this->watermark || $this->maxBytes !== null;
    }
}
