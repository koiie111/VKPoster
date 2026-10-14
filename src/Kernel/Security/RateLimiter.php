<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Support\Clock;
use Redis;

/**
 * Sliding-window rate limiter on Redis sorted sets (one entry per hit, evaluated atomically in Lua).
 *
 * `attempt()` records a hit only when it is allowed, so a blocked client does not extend its own ban.
 */
final class RateLimiter
{
    private const SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local max = tonumber(ARGV[3])
        redis.call('ZREMRANGEBYSCORE', key, 0, now - window)
        local count = redis.call('ZCARD', key)
        if count < max then
            redis.call('ZADD', key, now, ARGV[4])
            redis.call('PEXPIRE', key, window)
            return {1, max - count - 1, 0}
        end
        local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
        local retry = window - (now - tonumber(oldest[2]))
        return {0, 0, retry}
        LUA;

    public function __construct(
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly string $prefix = 'rl:',
    ) {
    }

    /**
     * @param string $key bucket id, e.g. `login:203.0.113.5`
     * @param int $max allowed hits per window
     * @param int $windowSeconds window length
     */
    public function attempt(string $key, int $max, int $windowSeconds, bool $failClosed = false): RateLimitResult
    {
        // phpredis may implicitly connect to localhost on eval; a security gate must use
        // only the explicitly configured, established connection.
        if (!$this->redis->isConnected()) {
            if ($failClosed) {
                throw new \RuntimeException('Rate limiter unavailable.');
            }
            return new RateLimitResult(true, 0, 0);
        }
        $nowMs = (int) floor((float) $this->clock->now()->format('U.u') * 1000);
        $result = $this->redis->eval(
            self::SCRIPT,
            [$this->prefix . $key, (string) $nowMs, (string) ($windowSeconds * 1000), (string) $max, $nowMs . ':' . bin2hex(random_bytes(6))],
            1,
        );
        if (!is_array($result) || count($result) !== 3
            || !isset($result[0], $result[1], $result[2])
            || !is_int($result[0]) || !in_array($result[0], [0, 1], true)
            || !is_int($result[1]) || !is_int($result[2])
            || $result[1] < 0 || $result[2] < 0) {
            // Infrastructure failure is not a rejected hit (429): PIN callers translate
            // this safe error to 503, before verifying a PIN or granting session access.
            if ($failClosed) {
                throw new \RuntimeException('Rate limiter unavailable.');
            }
            return new RateLimitResult(true, 0, 0);
        }

        return new RateLimitResult(
            $result[0] === 1,
            $result[1],
            (int) ceil($result[2] / 1000),
        );
    }

    /**
     * Forget all hits of a bucket (e.g. after a successful login).
     */
    public function clear(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
