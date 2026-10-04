<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use SensitiveParameter;

/**
 * Authenticity of webhook calls. Two secrets protect the endpoint: the one in the URL path (`TELEGRAM_WEBHOOK_SECRET`) and the
 * `X-Telegram-Bot-Api-Secret-Token` header, which Telegram sends back unchanged from `setWebhook` and which we derive from the
 * first one. Both are checked before the body is looked at.
 */
final class TelegramWebhook
{
    public static function headerToken(#[SensitiveParameter] string $secret): string
    {
        return substr(hash_hmac('sha256', 'telegram-webhook-header', $secret), 0, 48);
    }

    public static function isAuthentic(string $configuredSecret, string $pathSecret, ?string $header): bool
    {
        if ($configuredSecret === '' || $header === null) {
            return false;
        }

        return hash_equals($configuredSecret, $pathSecret) && hash_equals(self::headerToken($configuredSecret), $header);
    }

    public static function url(string $publicBaseUrl, #[SensitiveParameter] string $secret): string
    {
        return rtrim($publicBaseUrl, '/') . '/webhooks/telegram/' . $secret;
    }
}
