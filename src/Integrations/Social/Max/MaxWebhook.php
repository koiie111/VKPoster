<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use SensitiveParameter;

/**
 * Authenticity of webhook calls. Two secrets protect the endpoint: the one in the URL path (`MAX_WEBHOOK_SECRET`) and the
 * `X-Max-Bot-Api-Secret` header, which MAX sends back unchanged from the subscription and which we derive from the first one.
 * Both are checked before the body is looked at.
 */
final class MaxWebhook
{
    public static function headerToken(#[SensitiveParameter] string $secret): string
    {
        return substr(hash_hmac('sha256', 'max-webhook-header', $secret), 0, 48);
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
        return rtrim($publicBaseUrl, '/') . '/webhooks/max/' . $secret;
    }
}
