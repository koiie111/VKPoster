<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use JsonException;

/**
 * Immutable HTTP request. The only place (besides `Session`) that touches PHP superglobals.
 *
 * Client IP and scheme honour `X-Forwarded-*` headers only when the direct peer is in the
 * trusted-proxy list (`TRUSTED_PROXIES`); otherwise those headers are ignored.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body parsed form body (or JSON object)
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     * @param array<string, string> $headers lower-cased names
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     * @param array<string, mixed> $attributes
     * @param list<string> $trustedProxies IPs or CIDR ranges
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
        public readonly string $rawBody = '',
        public readonly array $attributes = [],
        public readonly array $trustedProxies = [],
    ) {
    }

    /**
     * @param list<string> $trustedProxies
     */
    public static function fromGlobals(array $trustedProxies = []): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        $rawBody = $method === 'GET' || $method === 'HEAD' ? '' : (string) file_get_contents('php://input');

        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');

        return self::create(
            $method,
            is_string($path) && $path !== '' ? $path : '/',
            $query,
            $post,
            self::normalizeFiles($_FILES),
            $headers,
            $cookies,
            $server,
            $rawBody,
            $trustedProxies,
        );
    }

    /**
     * Build a request, parsing a JSON body and applying the `_method` override (POST forms only).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxies
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $files = [],
        array $headers = [],
        array $cookies = [],
        array $server = [],
        string $rawBody = '',
        array $trustedProxies = [],
    ): self {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $method = strtoupper($method);
        if ($body === [] && $rawBody !== '' && str_contains($headers['content-type'] ?? '', 'application/json')) {
            try {
                $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $body = $decoded;
                }
            } catch (JsonException) {
                // malformed JSON leaves the body empty; validation reports missing fields
            }
        }
        if ($method === 'POST' && isset($body['_method']) && is_string($body['_method'])) {
            $override = strtoupper($body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, $path, $query, $body, $files, $headers, $cookies, $server, $rawBody, [], $trustedProxies);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Value from the body, then the query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->query + $this->body;
    }

    /**
     * Decoded JSON body (empty array when the body is not a JSON object).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return str_contains($this->header('content-type') ?? '', 'application/json') ? $this->body : [];
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains($this->header('accept') ?? '', 'application/json');
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->files,
            $this->headers,
            $this->cookies,
            $this->server,
            $this->rawBody,
            $attributes,
            $this->trustedProxies,
        );
    }

    /**
     * Client IP address. `X-Forwarded-For` is read right-to-left, skipping trusted proxies.
     */
    public function ip(): string
    {
        $peer = is_string($this->server['REMOTE_ADDR'] ?? null) ? $this->server['REMOTE_ADDR'] : '0.0.0.0';
        if (!$this->isTrustedProxy($peer)) {
            return $peer;
        }
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded === null) {
            return $peer;
        }
        $candidate = $peer;
        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $candidate = $hop;
            if (!$this->isTrustedProxy($hop)) {
                break;
            }
        }

        return $candidate;
    }

    public function scheme(): string
    {
        $peer = is_string($this->server['REMOTE_ADDR'] ?? null) ? $this->server['REMOTE_ADDR'] : '';
        if ($this->isTrustedProxy($peer)) {
            $proto = strtolower($this->header('x-forwarded-proto') ?? '');
            if ($proto === 'https' || $proto === 'http') {
                return $proto;
            }
        }
        $https = $this->server['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Host header without port validation (nginx rejects malformed ones).
     */
    public function host(): string
    {
        return strtolower($this->header('host') ?? '');
    }

    public function isReadMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $entry) {
            if (self::ipInRange($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }
        [$subnet, $bits] = explode('/', $range, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * @param array<array-key, mixed> $files raw `$_FILES`
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    private static function normalizeFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $spec) {
            if (!is_array($spec) || !isset($spec['name'], $spec['tmp_name'], $spec['size'], $spec['error'])) {
                continue;
            }
            if (is_array($spec['name'])) {
                $list = [];
                foreach (array_keys($spec['name']) as $i) {
                    $list[] = new UploadedFile(
                        (string) $spec['name'][$i],
                        (string) $spec['tmp_name'][$i],
                        (int) $spec['size'][$i],
                        (int) $spec['error'][$i],
                    );
                }
                $result[(string) $key] = $list;
            } else {
                $result[(string) $key] = new UploadedFile(
                    (string) $spec['name'],
                    (string) $spec['tmp_name'],
                    (int) $spec['size'],
                    (int) $spec['error'],
                );
            }
        }

        return $result;
    }
}
