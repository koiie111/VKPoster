<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Signs Telegram Login Widget data the way Telegram does, for tests.
 */
final class TelegramSigner
{
    /**
     * @param array<string, string> $fields
     * @return array<string, string> the fields plus a valid `hash`
     */
    public static function sign(array $fields, string $botToken = TestEnv::TELEGRAM_TOKEN): array
    {
        ksort($fields);
        $lines = array_map(static fn (string $k, string $v): string => $k . '=' . $v, array_keys($fields), $fields);
        $fields['hash'] = hash_hmac('sha256', implode("\n", $lines), hash('sha256', $botToken, true));

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public static function user(int $authDate, string $id = '777000', string $first = 'Иван', string $last = 'Петров'): array
    {
        return self::sign(['id' => $id, 'first_name' => $first, 'last_name' => $last, 'username' => 'ivan_p', 'photo_url' => 'https://t.me/i/userpic/320/x.jpg', 'auth_date' => (string) $authDate]);
    }
}
