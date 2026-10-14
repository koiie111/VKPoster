<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Domain\Automation\Words;

/**
 * Decides whether a post of the source channel is cross-posted: hashtags that must be present, hashtags that forbid it, and
 * whether forwarded posts are skipped. `reason()` returns the reason in Russian when the post is skipped, null when it passes.
 */
final class CrosspostFilter
{
    /**
     * @param list<string> $includeTags lower case, without "#"; empty = no requirement (any one of them is enough)
     * @param list<string> $excludeTags lower case, without "#"
     */
    public function __construct(
        public readonly array $includeTags = [],
        public readonly array $excludeTags = [],
        public readonly bool $skipForwarded = true,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(self::tags($data['include_tags'] ?? []), self::tags($data['exclude_tags'] ?? []), ($data['skip_forwarded'] ?? true) !== false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['include_tags' => $this->includeTags, 'exclude_tags' => $this->excludeTags, 'skip_forwarded' => $this->skipForwarded];
    }

    /**
     * Hashtags from a comma, space or line separated string or a list: lower case, no "#", at most 20, valid characters only.
     *
     * @return list<string>
     */
    public static function tags(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : (is_string($raw) ? Words::split('/[\s,;]+/u', $raw) : []);
        $tags = [];
        foreach ($items as $item) {
            $tag = mb_strtolower(ltrim(trim((string) $item), '#'));
            if ($tag !== '' && mb_strlen($tag) <= 64 && preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1) {
                $tags[$tag] = true;
            }
        }

        return array_slice(array_keys($tags), 0, 20);
    }

    /**
     * @return list<string> the hashtags of a text, lower case, without "#"
     */
    public static function hashtagsOf(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]+)/u', $text, $m);

        return array_values(array_unique(array_map('mb_strtolower', $m[1])));
    }

    public function reason(SourceMessage $message, string $fullText): ?string
    {
        if ($this->skipForwarded && $message->forwarded) {
            return 'Пересланный пост пропущен.';
        }
        $tags = self::hashtagsOf($fullText);
        if ($this->excludeTags !== [] && array_intersect($this->excludeTags, $tags) !== []) {
            return 'В посте есть хэштег-исключение.';
        }
        if ($this->includeTags !== [] && array_intersect($this->includeTags, $tags) === []) {
            return 'В посте нет нужного хэштега.';
        }

        return null;
    }
}
