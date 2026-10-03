<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable HTTP response. Build with the static factories, tweak with `with*()`, emit with `send()`.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies raw `Set-Cookie` header values
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, $text, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws JsonException
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
        );
    }

    /**
     * Redirect to a relative URL (`/path?x=1`). Absolute and protocol-relative targets are refused
     * to rule out open redirects; use `redirectToTrusted()` for allow-listed external hosts.
     *
     * @throws InvalidArgumentException
     */
    public static function redirect(string $to, int $status = 302): self
    {
        if (!self::isRelativeUrl($to)) {
            throw new InvalidArgumentException('Redirect target must be a relative URL.');
        }

        return new self($status, '', ['Location' => $to]);
    }

    /**
     * Redirect to an absolute URL whose host is in `$allowedHosts`.
     *
     * @param list<string> $allowedHosts
     * @throws InvalidArgumentException
     */
    public static function redirectToTrusted(string $url, array $allowedHosts, int $status = 302): self
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, array_map('strtolower', $allowedHosts), true)) {
            throw new InvalidArgumentException('Redirect host is not allowed.');
        }

        return new self($status, '', ['Location' => $url]);
    }

    /**
     * True for `/path` style URLs (not `//host`, `/\host`, or anything with a scheme or control chars).
     */
    public static function isRelativeUrl(string $url): bool
    {
        return $url !== ''
            && $url[0] === '/'
            && !str_starts_with($url, '//')
            && !str_starts_with($url, '/\\')
            && preg_match('/[\x00-\x1F\x7F\\\\]/', $url) !== 1;
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->body, $this->headers, $this->cookies);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $body, $this->headers, $this->cookies);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return new self($this->status, $this->body, $headers, $this->cookies);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Add a `Set-Cookie` header. SameSite defaults to Lax and HttpOnly to true.
     */
    public function withCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        string $path = '/',
    ): self {
        $cookie = sprintf('%s=%s; Path=%s; SameSite=%s', $name, rawurlencode($value), $path, $sameSite);
        if ($maxAge > 0) {
            $cookie .= '; Max-Age=' . $maxAge;
        } elseif ($maxAge < 0) {
            $cookie .= '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }

        return new self($this->status, $this->body, $this->headers, [...$this->cookies, $cookie]);
    }

    /**
     * Emit status, headers and body. Not used in tests (they inspect the object).
     *
     * @codeCoverageIgnore
     */
    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if (!$headOnly) {
            echo $this->body;
        }
    }
}
