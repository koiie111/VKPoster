<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "I am alive" marks of the long-running processes (the worker and the scheduler), kept in Redis for an hour so the admin area can tell a
 * stopped process from a quiet one. A beat is written at most every few seconds per process, so a busy loop does not hammer Redis.
 * Redis trouble is swallowed: a heartbeat must never stop real work.
 */
final class Heartbeat
{
    private const TTL = 3600;
    private const EVERY = 5;

    /** @var array<string, int> */
    private array $last = [];

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function beat(string $name): void
    {
        $now = time();
        if (isset($this->last[$name]) && $now - $this->last[$name] < self::EVERY) {
            return;
        }
        $this->last[$name] = $now;
        try {
            $this->redis->setex('heartbeat:' . $name, self::TTL, (string) $now);
        } catch (\Throwable) {
        }
    }

    /**
     * When the process last reported in; null when it never did (or not in the last hour).
     */
    public function at(string $name): ?DateTimeImmutable
    {
        try {
            $value = $this->redis->get('heartbeat:' . $name);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && ctype_digit($value) ? new DateTimeImmutable('@' . $value, new DateTimeZone('UTC')) : null;
    }
}
