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
        private readonly ?EgressRoutes $egress = null,
        private readonly ?EgressHealth $egressHealth = null,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $routes = $this->egress ?? new EgressRoutes(new \App\Kernel\Env([]));
        $provider = is_string($options['egress_provider'] ?? null) ? $options['egress_provider'] : $routes->providerFor($url);
        unset($options['egress_provider']);
        $route = $routes->route($provider);
        $userUrl = ($options['user_url'] ?? false) === true;
        unset($options['user_url']);
        if ($userUrl && $route['mode'] !== 'direct') {
            throw new SsrfException('User URL proxying is disabled: remote DNS would bypass address pinning.');
        }
        $options['proxy'] = $route['proxy'];
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
            try {
                $response = $client->request($method, $url, $hopOptions);
                $this->egressHealth?->record($provider, $route['mode'], true);
            } catch (\GuzzleHttp\Exception\GuzzleException $error) {
                $this->egressHealth?->record($provider, $route['mode'], false);
                if ($route['mode'] === 'direct') {
                    throw $error;
                }
                $this->logger?->warning('egress.transport_failed', ['provider' => $provider, 'mode' => $route['mode']]);
                $request = new \GuzzleHttp\Psr7\Request($method, $url);
                // Guzzle 8 separates pre-dispatch ConnectException from post-connect NetworkTimeoutException.
                if ($error instanceof \GuzzleHttp\Exception\ConnectException) {
                    throw new \GuzzleHttp\Exception\ConnectException('Egress connection unavailable.', $request);
                }
                throw new \GuzzleHttp\Exception\RequestException('Egress outcome is uncertain.', $request);
            }
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
            $next = self::resolveLocation($url, $location);
            $credentialHeaders = array_change_key_case(is_array($options['headers'] ?? null) ? $options['headers'] : [], CASE_LOWER);
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower((string) parse_url($next, PHP_URL_HOST))
                && (!in_array($method, ['GET', 'HEAD'], true) || isset($credentialHeaders['authorization']) || isset($credentialHeaders['cookie']))) {
                throw new SsrfException('Credential-bearing cross-origin redirect denied.');
            }
            $url = $next;
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
