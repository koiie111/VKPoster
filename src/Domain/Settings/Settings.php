<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Settings the owner changes in the admin area without a deploy (stored in `app_settings`, any JSON value).
 * Reads are cached inside the process for a few seconds, so a long-running worker notices a change quickly but does not ask the database
 * on every call; `set()` drops the cache of the process that made the change.
 */
final class Settings
{
    private const TTL_SECONDS = 5;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    private int $loadedAt = 0;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
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
        $this->cache = null;
    }

    public function forget(string $name): void
    {
        $this->db->execute('DELETE FROM app_settings WHERE name = ?', [$name]);
        $this->cache = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if ($this->cache !== null && time() - $this->loadedAt < self::TTL_SECONDS) {
            return $this->cache;
        }
        $values = [];
        foreach ($this->db->select('SELECT name, value_json FROM app_settings') as $row) {
            $values[(string) $row['name']] = json_decode((string) $row['value_json'], true);
        }
        $this->loadedAt = time();

        return $this->cache = $values;
    }
}
