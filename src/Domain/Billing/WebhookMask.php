<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Hides what must not sit in a journal that staff read: the body of a provider's notification is kept for support, but card data, contact
 * details, tokens and signatures are replaced before it is stored. Fields are recognised by name; long digit runs (card numbers) are
 * reduced to their last four digits wherever they appear. The result is cut to a size that fits the column.
 */
final class WebhookMask
{
    public const MASK = '[скрыто]';
    public const LIMIT = 8000;

    private const SECRET_KEY = '/(password|passwd|secret|token|signature|authorization|cookie|api[_-]?key|card|pan|cvc|cvv|email|phone|customer|receipt|payer|account|ip)/i';

    public static function apply(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $text = self::json(self::walk($decoded));
        } else {
            // Not JSON (a form post): mask `key=value` pairs by key.
            parse_str($body, $pairs);
            $text = $pairs === [] ? self::digits($body) : self::json(self::walk($pairs));
        }

        return mb_strlen($text) > self::LIMIT ? mb_substr($text, 0, self::LIMIT) . "\n…" : $text;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, int $depth = 0): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $result[$key] = self::MASK;
            } elseif (is_array($value)) {
                $result[$key] = $depth < 10 ? self::walk($value, $depth + 1) : self::MASK;
            } elseif (is_string($value)) {
                $result[$key] = self::digits($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function digits(string $text): string
    {
        return (string) preg_replace_callback('/\b\d{13,19}\b/', static fn (array $m): string => str_repeat('•', strlen($m[0]) - 4) . substr($m[0], -4), $text);
    }
}
