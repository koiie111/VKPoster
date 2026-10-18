<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Domain separation: compromise of the renderer cannot expose the Crypto master key. */
final class AnalyticsPdfKey
{
    public static function derive(#[\SensitiveParameter] string $master): string
    {
        if ($master === '') {
            throw new \RuntimeException('PDF signing key unavailable.');
        }
        return hash_hmac('sha256', 'analytics-pdf-v1', $master);
    }
}
