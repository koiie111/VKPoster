<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether an IP address (v4 or v6) lies inside a CIDR range such as `185.71.76.0/27`; a bare address is a range of one.
 */
final class IpRange
{
    /**
     * @param list<string> $ranges
     */
    public static function containsAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::contains($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function contains(string $ip, string $range): bool
    {
        $address = inet_pton($ip);
        if ($address === false) {
            return false;
        }
        [$base, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $network = inet_pton((string) $base);
        if ($network === false || strlen($network) !== strlen($address)) {
            return false;
        }
        $bits = $bits === null ? strlen($network) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
