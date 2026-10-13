<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

/**
 * What is done to the text of a cross-posted post: the channel's signature is cut out, links are replaced, a tail is added.
 * Works on the editor's markup, so replaced addresses inside links change too.
 */
final class CrosspostTransform
{
    public const MAX_TAIL = 2000;
    public const MAX_LINK_RULES = 10;

    /**
     * @param list<string> $signatures pieces of text to remove (e.g. "@mychannel", "Подписаться на канал")
     * @param list<array{from: string, to: string}> $links plain-text replacements, applied in order
     */
    public function __construct(
        public readonly array $signatures = [],
        public readonly array $links = [],
        public readonly string $tail = '',
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $signatures = [];
        $raw = $data['signatures'] ?? [];
        foreach (is_string($raw) ? preg_split('/\R/u', $raw, -1, PREG_SPLIT_NO_EMPTY) : (is_array($raw) ? $raw : []) as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $signatures[] = mb_substr($item, 0, 200);
            }
        }
        $links = [];
        foreach (is_array($data['links'] ?? null) ? $data['links'] : [] as $rule) {
            $from = is_array($rule) && is_string($rule['from'] ?? null) ? trim($rule['from']) : '';
            $to = is_array($rule) && is_string($rule['to'] ?? null) ? trim($rule['to']) : '';
            if ($from !== '') {
                $links[] = ['from' => mb_substr($from, 0, 500), 'to' => mb_substr($to, 0, 500)];
            }
        }

        return new self(array_slice($signatures, 0, 5), array_slice($links, 0, self::MAX_LINK_RULES), mb_substr(trim(is_string($data['tail'] ?? null) ? $data['tail'] : ''), 0, self::MAX_TAIL));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['signatures' => $this->signatures, 'links' => $this->links, 'tail' => $this->tail];
    }

    public function apply(string $markup): string
    {
        $text = $markup;
        foreach ($this->signatures as $signature) {
            $lines = explode("\n", $text);
            foreach ($lines as $i => $line) {
                $cut = trim(preg_replace('/ {2,}/', ' ', str_ireplace($signature, '', $line)) ?? $line);
                if ($cut !== trim($line)) {
                    // A line that held only the signature (and perhaps a decoration) goes; otherwise only the signature goes.
                    $lines[$i] = preg_match('/^[\p{P}\p{S}\s]*$/u', $cut) === 1 ? "\0" : $cut;
                }
            }
            $text = implode("\n", array_filter($lines, static fn (string $line): bool => $line !== "\0"));
        }
        foreach ($this->links as $rule) {
            $text = str_replace($rule['from'], $rule['to'], $text);
        }
        $text = rtrim($text);
        if ($this->tail !== '') {
            $text = ($text === '' ? '' : $text . "\n\n") . $this->tail;
        }

        return $text;
    }
}
