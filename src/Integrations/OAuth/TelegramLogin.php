<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Support\Clock;

/**
 * Telegram Login Widget. Telegram redirects the browser to our callback with the user's fields and a
 * `hash`: HMAC-SHA256 of the sorted `key=value` lines, keyed with SHA-256 of the bot token. The check
 * proves the data came from Telegram; `auth_date` must be at most 24 hours old. Replay of a still-fresh
 * link is stopped by the caller (`SocialAuthService`), which accepts every hash only once.
 *
 * Telegram never reports an email address.
 */
final class TelegramLogin
{
    public const MAX_AGE = 86400;

    public function __construct(
        private readonly Clock $clock,
        private readonly string $botToken,
        private readonly string $botName,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->botName !== '';
    }

    public function botName(): string
    {
        return $this->botName;
    }

    /**
     * @param array<array-key, mixed> $data every query parameter Telegram sent, except our own `state`
     * @throws OAuthException
     */
    public function verify(array $data): SocialProfile
    {
        $hash = $data['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new OAuthException('telegram: missing or malformed hash');
        }
        unset($data['hash']);
        $fields = [];
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                throw new OAuthException('telegram: unexpected field type');
            }
            $fields[(string) $key] = $value;
        }
        ksort($fields);
        $lines = array_map(static fn (string $key, string $value): string => $key . '=' . $value, array_keys($fields), $fields);
        $expected = hash_hmac('sha256', implode("\n", $lines), hash('sha256', $this->botToken, true));
        if (!hash_equals($expected, $hash)) {
            throw new OAuthException('telegram: bad signature');
        }
        $authDate = $fields['auth_date'] ?? '';
        $id = $fields['id'] ?? '';
        if (!ctype_digit($authDate) || !ctype_digit($id)) {
            throw new OAuthException('telegram: bad id or auth_date');
        }
        $age = $this->clock->now()->getTimestamp() - (int) $authDate;
        if ($age > self::MAX_AGE || $age < -60) {
            throw new OAuthException('telegram: auth_date is outside the allowed window');
        }
        $name = trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? ''));
        if ($name === '') {
            $name = $fields['username'] ?? '';
        }
        $photo = isset($fields['photo_url']) && str_starts_with($fields['photo_url'], 'https://') ? $fields['photo_url'] : null;

        return new SocialProfile('telegram', $id, null, false, $name, $photo);
    }
}
