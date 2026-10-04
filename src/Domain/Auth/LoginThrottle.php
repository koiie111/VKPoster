<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Support\Clock;
use Redis;

/**
 * Per-account brute-force protection for sign-in, kept in Redis and keyed by a hash of the email,
 * so it behaves identically for existing and non-existing accounts (no way to probe for accounts).
 *
 * From the 5th consecutive failure the account is locked for 30 s, doubling with every further failure
 * up to 15 minutes. Failures are forgotten after an hour without new ones or after a successful login.
 * The lock is a speed bump, not a ban: an attacker can slow the owner down but never get in faster.
 */
final class LoginThrottle
{
    public const FREE_ATTEMPTS = 4;
    public const NOTIFY_AT = 10;
    private const MAX_DELAY = 900;
    private const FORGET_AFTER = 3600;

    public function __construct(
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly string $prefix = 'auth:',
    ) {
    }

    /**
     * Seconds the caller must still wait before another attempt (0 = go ahead).
     */
    public function retryAfter(string $email): int
    {
        $until = $this->redis->get($this->key('lock', $email));
        if (!is_string($until)) {
            return 0;
        }

        return max(0, (int) $until - $this->clock->now()->getTimestamp());
    }

    /**
     * Record a failed attempt.
     *
     * @return int consecutive failures so far
     */
    public function fail(string $email): int
    {
        $countKey = $this->key('fail', $email);
        $count = (int) $this->redis->incr($countKey);
        $this->redis->expire($countKey, self::FORGET_AFTER);
        if ($count > self::FREE_ATTEMPTS) {
            $delay = min(self::MAX_DELAY, 30 * (2 ** min(10, $count - self::FREE_ATTEMPTS - 1)));
            $this->redis->setex($this->key('lock', $email), $delay, (string) ($this->clock->now()->getTimestamp() + $delay));
        }

        return $count;
    }

    public function clear(string $email): void
    {
        $this->redis->del($this->key('fail', $email), $this->key('lock', $email));
    }

    private function key(string $kind, string $email): string
    {
        return $this->prefix . $kind . ':' . hash('sha256', mb_strtolower(trim($email)));
    }
}
