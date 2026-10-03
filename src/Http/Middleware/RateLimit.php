<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Security\RateLimiter;
use Closure;

/**
 * Per-IP rate limit for a route or group. Attach as
 * `[RateLimit::class, ['bucket' => 'login', 'max' => 5, 'seconds' => 60]]`; exceeding it gives 429 with `Retry-After`.
 */
final class RateLimit implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $bucket = 'default',
        private readonly int $max = 60,
        private readonly int $seconds = 60,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->limiter->attempt($this->bucket . ':' . $request->ip(), $this->max, $this->seconds);
        if (!$result->allowed) {
            throw new HttpException(429, 'Too many requests', ['Retry-After' => (string) max(1, $result->retryAfter)]);
        }

        return $next($request);
    }
}
