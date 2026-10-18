<?php

declare(strict_types=1);

namespace App\Domain\Link;

/**
 * How links get their UTM tags: on or off and a template for each tag. A template is plain text with placeholders that are filled in
 * when a post goes out: `{platform}` (vk, telegram, ...), `{channel}` (the channel's name), `{date}` (2026-10-18) and `{month}`
 * (2026-10). `source` is required while the rule is on; the other tags are optional.
 */
final class UtmRule
{
    public const PLACEHOLDERS = ['platform', 'channel', 'date', 'month'];
    public const MAX_TEMPLATE = 100;

    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $source = '{platform}',
        public readonly string $medium = 'social',
        public readonly string $campaign = '{month}',
        public readonly string $content = '',
        public readonly string $term = '',
    ) {
    }

    /**
     * @param array<mixed> $data whatever the form or the database holds; unknown keys and non-strings are ignored
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): string => is_string($data[$key] ?? null) ? mb_substr(trim($data[$key]), 0, self::MAX_TEMPLATE) : '';
        $flag = $data['enabled'] ?? false;

        return new self($flag === true || $flag === 1 || $flag === '1' || $flag === 'on', $text('source'), $text('medium'), $text('campaign'), $text('content'), $text('term'));
    }

    /**
     * @return array{enabled: bool, source: string, medium: string, campaign: string, content: string, term: string}
     */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'source' => $this->source, 'medium' => $this->medium, 'campaign' => $this->campaign, 'content' => $this->content, 'term' => $this->term];
    }

    /**
     * The tag names and templates that are set, in the order they are appended.
     *
     * @return array<string, string> e.g. `['utm_source' => '{platform}']`
     */
    public function templates(): array
    {
        $all = ['utm_source' => $this->source, 'utm_medium' => $this->medium, 'utm_campaign' => $this->campaign, 'utm_content' => $this->content, 'utm_term' => $this->term];

        return array_filter($all, static fn (string $v): bool => $v !== '');
    }

    /**
     * Mistakes a person can fix, in Russian.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->enabled && $this->source === '') {
            $problems[] = 'Укажите источник (utm_source), например {platform}.';
        }
        foreach ($this->templates() as $name => $template) {
            if (preg_match('/[{}]/', (string) preg_replace('/\{(?:' . implode('|', self::PLACEHOLDERS) . ')\}/', '', $template)) === 1) {
                $problems[] = 'В метке ' . $name . ' есть незнакомая подстановка. Доступны: {' . implode('}, {', self::PLACEHOLDERS) . '}.';
            }
        }

        return $problems;
    }
}
