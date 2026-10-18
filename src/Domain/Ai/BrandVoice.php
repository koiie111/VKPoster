<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * How a workspace talks: tone, words never to use and up to three example posts. It is mixed into every prompt so the answers sound
 * like the brand. Lengths are capped so it cannot be used to smuggle a huge prompt.
 */
final class BrandVoice
{
    public const MAX_TONE = 300;
    public const MAX_FORBIDDEN = 30;
    public const MAX_EXAMPLES = 3;
    public const MAX_EXAMPLE = 800;
    public const MAX_RULES = 1500;

    /**
     * @param list<string> $forbidden
     * @param list<string> $examples
     */
    public function __construct(
        public readonly string $tone = '',
        public readonly array $forbidden = [],
        public readonly array $examples = [],
        /** Free-form format rules: structure, length, emoji, signature, hashtags, links. */
        public readonly string $rules = '',
    ) {
    }

    /**
     * @param array<array-key, mixed> $data decoded JSON or a form: `tone`, `forbidden` (list or comma/line separated text), `examples` (list)
     */
    public static function fromArray(array $data): self
    {
        $tone = is_string($data['tone'] ?? null) ? trim(mb_substr($data['tone'], 0, self::MAX_TONE)) : '';
        $forbidden = $data['forbidden'] ?? [];
        if (is_string($forbidden)) {
            $forbidden = preg_split('/[,\n;]+/u', $forbidden);
            $forbidden = $forbidden === false ? [] : $forbidden;
        }
        $words = [];
        foreach (is_array($forbidden) ? $forbidden : [] as $word) {
            $word = is_string($word) ? trim(mb_substr($word, 0, 40)) : '';
            if ($word !== '' && !in_array($word, $words, true) && count($words) < self::MAX_FORBIDDEN) {
                $words[] = $word;
            }
        }
        $examples = [];
        foreach (is_array($data['examples'] ?? null) ? $data['examples'] : [] as $example) {
            $example = is_string($example) ? trim(mb_substr($example, 0, self::MAX_EXAMPLE)) : '';
            if ($example !== '' && count($examples) < self::MAX_EXAMPLES) {
                $examples[] = $example;
            }
        }

        $rules = is_string($data['rules'] ?? null) ? trim(mb_substr($data['rules'], 0, self::MAX_RULES)) : '';

        return new self($tone, $words, $examples, $rules);
    }

    public function isEmpty(): bool
    {
        return $this->tone === '' && $this->forbidden === [] && $this->examples === [] && $this->rules === '';
    }

    /**
     * The style of one channel on top of the workspace's: the channel's tone and examples win when set, the format rules are added
     * after the workspace's (the more specific ones come last), forbidden words of both apply.
     */
    public function withChannel(self $channel): self
    {
        return new self(
            $channel->tone !== '' ? $channel->tone : $this->tone,
            array_values(array_unique(array_merge($this->forbidden, $channel->forbidden))),
            $channel->examples !== [] ? $channel->examples : $this->examples,
            trim($this->rules . ($this->rules !== '' && $channel->rules !== '' ? "\n" : '') . $channel->rules),
        );
    }

    /** @return array{tone: string, forbidden: list<string>, examples: list<string>, rules: string} */
    public function toArray(): array
    {
        return ['tone' => $this->tone, 'forbidden' => $this->forbidden, 'examples' => $this->examples, 'rules' => $this->rules];
    }
}
