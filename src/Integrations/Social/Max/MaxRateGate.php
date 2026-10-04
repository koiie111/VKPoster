<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use Redis;
use SensitiveParameter;

/**
 * MAX accepts two messages per second to one chat. Every send asks the gate first: it counts sends in the current second in Redis
 * (shared by all workers; keyed by a hash of the token and the chat, never the token itself) and sleeps until the next second when
 * the budget is spent. Without Redis (unit tests) it lets everything through.
 */
final class MaxRateGate
{
    public const PER_SECOND = 2;

    public function __construct(private readonly ?Redis $redis = null, private readonly int $perSecond = self::PER_SECOND)
    {
    }

    public function wait(#[SensitiveParameter] string $token, int $chatId): void
    {
        if ($this->redis === null) {
            return;
        }
        $id = substr(hash('sha256', $token . ':' . $chatId), 0, 16);
        for ($i = 0; $i < 6; ++$i) {
            $now = microtime(true);
            $key = 'max:rate:' . $id . ':' . (int) $now;
            $count = (int) $this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, 3);
            }
            if ($count <= $this->perSecond) {
                return;
            }
            usleep((int) ((ceil($now) - $now) * 1_000_000) + 20_000);
        }
    }
}
