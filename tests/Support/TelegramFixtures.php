<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recorded (and trimmed) answers of the Telegram Bot API from `tests/Fixtures/telegram/`.
 */
final class TelegramFixtures
{
    /**
     * @param array<string, string> $replace placeholders such as `{{CODE}}` => value
     */
    public static function raw(string $name, array $replace = []): string
    {
        $path = dirname(__DIR__) . '/Fixtures/telegram/' . $name . '.json';
        $body = file_get_contents($path);
        if ($body === false) {
            throw new \LogicException('Missing Telegram fixture ' . $name);
        }

        return strtr($body, $replace);
    }

    /**
     * @param array<string, string> $replace
     * @return array<string, mixed>
     */
    public static function update(string $name, array $replace = []): array
    {
        $decoded = json_decode(self::raw($name, $replace), true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
