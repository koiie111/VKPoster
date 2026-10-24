<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Support\Clock;
use Redis;

/** Scheduler-owned dependency probes. HTTP only reads a bounded-age result and never probes dependencies. */
final class ReadinessState
{
    /** @param \Closure():Redis $redis @param \Closure():array<string,string> $probe */
    public function __construct(
        private readonly \Closure $redis,
        private readonly Clock $clock,
        private readonly \Closure $probe,
        private readonly string $identity,
    ) {
    }

    public function refresh(): void
    {
        $redis = ($this->redis)();
        $key = $this->key();
        $owner = bin2hex(random_bytes(12));
        if (!$redis->set($key . ':lock', $owner, ['nx', 'ex' => 45])) {
            return;
        }
        try {
            $old = $this->read($redis);
            if ($old !== null && $old['at'] > $this->clock->now()->getTimestamp() - 15) {
                return;
            }
            try {
                $result = ($this->probe)();
                $ok = !in_array('fail', $result, true);
            } catch (\Throwable) {
                $ok = false;
            }
            $redis->setex($key, 120, json_encode(['at' => $this->clock->now()->getTimestamp(), 'ok' => $ok], JSON_THROW_ON_ERROR));
        } finally {
            $redis->eval('if redis.call("get",KEYS[1])==ARGV[1] then return redis.call("del",KEYS[1]) else return 0 end', [$key . ':lock', $owner], 1);
        }
    }

    public function ready(): bool
    {
        try {
            $result = $this->read(($this->redis)());
            return $result !== null && $result['ok'] && $result['at'] >= $this->clock->now()->getTimestamp() - 90;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{at:int,ok:bool}|null */
    private function read(Redis $redis): ?array
    {
        $raw = $redis->get($this->key());
        if (!is_string($raw)) {
            return null;
        }
        $value = json_decode($raw, true);
        return is_array($value) && is_int($value['at'] ?? null) && is_bool($value['ok'] ?? null)
            ? ['at' => $value['at'], 'ok' => $value['ok']] : null;
    }

    private function key(): string
    {
        return 'readiness:' . $this->identity;
    }
}
