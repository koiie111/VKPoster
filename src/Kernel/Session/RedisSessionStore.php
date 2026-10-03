<?php

declare(strict_types=1);

namespace App\Kernel\Session;

use JsonException;
use Redis;

/**
 * Redis-backed session storage. Entries live under `sess:<sha256(id)>`, so a Redis dump does not
 * reveal usable session ids. Data is JSON (no PHP unserialize on stored values).
 */
final class RedisSessionStore implements SessionStore
{
    public function __construct(private readonly Redis $redis, private readonly string $prefix = 'sess:')
    {
    }

    public function read(string $id): ?array
    {
        $raw = $this->redis->get($this->key($id));
        if (!is_string($raw)) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $this->redis->setex($this->key($id), max(1, $ttl), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function destroy(string $id): void
    {
        $this->redis->del($this->key($id));
    }

    private function key(string $id): string
    {
        return $this->prefix . hash('sha256', $id);
    }
}
