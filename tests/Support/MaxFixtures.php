<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recorded (and trimmed) answers of the MAX bot API from `tests/Fixtures/max/`, built from the shapes in the MAX documentation
 * (no test reaches MAX). Chat ids are the ones inside the fixtures.
 */
final class MaxFixtures
{
    public const API = 'https://platform-api2.max.ru';
    public const TOKEN = 'max-own-bot-token-not-real-0123456789';
    public const CHANNEL = -72000000000001;
    public const GROUP = -72000000000002;

    /**
     * @param array<string, string> $replace placeholders such as `{{CODE}}` => value
     */
    public static function raw(string $name, array $replace = []): string
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/max/' . $name . '.json');
        if ($body === false) {
            throw new \LogicException('Missing MAX fixture ' . $name);
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
