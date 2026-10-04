<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns a `User-Agent` header into a short label for the device list ("Chrome на macOS").
 * Deliberately coarse: it only helps people recognise their own devices.
 */
final class UserAgent
{
    public static function describe(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Неизвестное устройство';
        }
        $browser = match (true) {
            str_contains($userAgent, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'EdgA/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Браузер',
        };
        $system = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : $browser . ' на ' . $system;
    }
}
