<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use App\Kernel\Exception\SsrfException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Guzzle-backed `HttpClientInterface` with safe defaults: connect timeout 5 s, total 20 s,
 * manual redirects, SSRF guard for user URLs, and logging that never records headers or bodies.
 */
final class GuzzleHttpClient implements HttpClientInterface
{
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $userUrl = ($options['user_url'] ?? false) === true;
        unset($options['user_url']);
        $options += ['connect_timeout' => 5, 'timeout' => 20, 'http_errors' => false];
        $options['allow_redirects'] = false;
        $client = $this->client ?? new Client();

        for ($hop = 0; ; ++$hop) {
            $hopOptions = $options;
            if ($userUrl) {
                $target = $this->guard->assertSafe($url);
                // Pin the connection to the address we validated (no second DNS lookup, no rebinding).
                $curl = is_array($hopOptions['curl'] ?? null) ? $hopOptions['curl'] : [];
                $address = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
                $curl[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $target['host'], $target['port'], $address)];
                $hopOptions['curl'] = $curl;
            }
            $started = microtime(true);
            $response = $client->request($method, $url, $hopOptions);
            $this->logger?->info('http.request', [
                'method' => $method,
                'host' => parse_url($url, PHP_URL_HOST),
                'status' => $response->getStatusCode(),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            if ($status < 300 || $status >= 400 || $location === '' || ($options['follow_redirects'] ?? true) === false) {
                return $response;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw new SsrfException('Too many redirects.');
            }
            $url = self::resolveLocation($url, $location);
            if (in_array($status, [301, 302, 303], true)) {
                $method = 'GET';
                unset($options['body'], $options['json'], $options['form_params'], $options['multipart']);
            }
        }
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }
}
