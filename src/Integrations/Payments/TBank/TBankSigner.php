<?php

declare(strict_types=1);

namespace App\Integrations\Payments\TBank;

use SensitiveParameter;

/**
 * The `Token` of the T-Bank acquiring API: SHA-256 of the root-level scalar parameters (without `Token` itself) plus the terminal `Password`,
 * sorted by parameter name and concatenated as values. Nested objects (`Receipt`, `DATA`) are not part of it. Booleans count as `true`/`false`.
 */
final class TBankSigner
{
    /**
     * @param array<string, mixed> $params
     */
    public static function token(array $params, #[SensitiveParameter] string $password): string
    {
        unset($params['Token']);
        $params['Password'] = $password;
        ksort($params, SORT_STRING);
        $joined = '';
        foreach ($params as $value) {
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }
            $joined .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return hash('sha256', $joined);
    }

    /**
     * @param array<string, mixed> $params the notification as received, including its `Token`
     */
    public static function verify(array $params, #[SensitiveParameter] string $password): bool
    {
        $given = $params['Token'] ?? null;

        return is_string($given) && hash_equals(self::token($params, $password), strtolower($given));
    }
}
