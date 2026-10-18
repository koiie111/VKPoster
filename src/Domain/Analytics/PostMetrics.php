<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Comparable counters with explicit availability; engagement is independent of views. */
final class PostMetrics
{
    /** @param array<string,string> $statuses Per-metric provider availability; never inferred zeroes. */
    public function __construct(
        public readonly ?int $views = null,
        public readonly ?int $reach = null,
        public readonly ?int $engagement = null,
        public readonly ?int $likes = null,
        public readonly ?int $comments = null,
        public readonly ?int $shares = null,
        public readonly array $statuses = []
    ) {
    }
    /** @return array<string,int|null> */
    public function toArray(): array
    {
        return ['views' => $this->views,
            'reach' => $this->reach,
            'engagement' => $this->engagement,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares];
    }
    public function er(): ?float
    {
        return $this->reach !== null && $this->reach > 0 && $this->engagement !== null ? round(100 * $this->engagement / $this->reach, 2) : null;
    }
    public static function counter(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
