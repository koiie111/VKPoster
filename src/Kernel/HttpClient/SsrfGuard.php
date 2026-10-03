<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use App\Kernel\Exception\SsrfException;
use Closure;

/**
 * Validates user-supplied URLs before we fetch them: http/https only, standard ports, no credentials,
 * and every resolved address must be public (no loopback, private, link-local or metadata ranges).
 * Returns the validated IP so the caller can pin the connection to it (defeats DNS rebinding).
 */
final class SsrfGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param (Closure(string): list<string>)|null $resolver host → IP list; defaults to real DNS (A and AAAA)
     * @param list<int> $allowedPorts
     */
    public function __construct(?Closure $resolver = null, private readonly array $allowedPorts = [80, 443, 8080, 8443])
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            foreach ($records === false ? [] : $records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            return $ips;
        };
    }

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     * @throws SsrfException
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Invalid URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfException('Only http and https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfException('URLs with credentials are not allowed.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            throw new SsrfException('Port is not allowed.');
        }
        $host = trim(strtolower($parts['host']), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new SsrfException('Host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new SsrfException('Address is not publicly routable.');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) must be judged by the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $extra = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '169.254.0.0/16', 'fc00::/7', 'fe80::/10', '::1/128', '64:ff9b::/96'];
        foreach ($extra as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;

        return $rest === 0 || (ord($ipBin[$bytes]) & ((0xFF << (8 - $rest)) & 0xFF)) === (ord($subnetBin[$bytes]) & ((0xFF << (8 - $rest)) & 0xFF));
    }
}
