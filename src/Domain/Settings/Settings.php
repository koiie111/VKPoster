<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Settings the owner changes in the admin area without a deploy (stored in `app_settings`, any JSON value; secrets never go here).
 * Reads are cached twice: inside the process for a few seconds (a long-running worker notices a change quickly without asking anybody on
 * every call) and in Redis for minutes (every web process shares one copy instead of reading the table). `set()` and `forget()` write the
 * table and drop the Redis copy, so a change shows up everywhere within the process cache time. Redis trouble is not an error: the table
 * is the truth.
 */
final class Settings
{
    private const TTL_SECONDS = 5;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    private int $loadedAt = 0;

    private const REDIS_KEY = 'app:settings';
    private const REDIS_TTL = 300;

    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly ?\Redis $redis = null)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function set(string $name, mixed $value, ?int $actorId): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $this->db->execute(
            'INSERT INTO app_settings (name, value_json, updated_by, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$name, $json, $actorId, DbTime::format($this->clock->now())],
        );
        $this->invalidate();
    }

    public function forget(string $name): void
    {
        $this->db->execute('DELETE FROM app_settings WHERE name = ?', [$name]);
        $this->invalidate();
    }

    private function invalidate(): void
    {
        $this->cache = null;
        try {
            $this->redis?->del(self::REDIS_KEY);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if ($this->cache !== null && time() - $this->loadedAt < self::TTL_SECONDS) {
            return $this->cache;
        }
        $values = $this->fromRedis();
        if ($values === null) {
            $values = [];
            foreach ($this->db->select('SELECT name, value_json FROM app_settings') as $row) {
                $values[(string) $row['name']] = json_decode((string) $row['value_json'], true);
            }
            try {
                $this->redis?->setex(self::REDIS_KEY, self::REDIS_TTL, json_encode($values, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
            }
        }
        $this->loadedAt = time();

        return $this->cache = $values;
    }

    /**
     * @return array<string, mixed>|null null when Redis has no copy (or cannot be asked)
     */
    private function fromRedis(): ?array
    {
        try {
            $json = $this->redis?->get(self::REDIS_KEY);
            $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
