<?php

declare(strict_types=1);

namespace App\Kernel\Database;

use App\Support\Fs;
use RuntimeException;

/**
 * Runs migrations from a directory in file-name order and records them in a `migrations` table by batch.
 * `rollback()` reverts the last batch (newest first), `fresh()` drops every table and migrates again.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $directory,
        private readonly string $table = 'migrations',
    ) {
    }

    /**
     * @return list<string> names of migrations applied
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = $this->applied();
        $pending = array_values(array_filter(array_keys($this->files()), static fn (string $n): bool => !isset($done[$n])));
        if ($pending === []) {
            return [];
        }
        $batch = $this->nextBatch();
        foreach ($pending as $name) {
            $this->load($name)->up($this->db);
            $this->db->table($this->table)->insert(['migration' => $name, 'batch' => $batch]);
        }

        return $pending;
    }

    /**
     * @return list<string> names of migrations reverted
     */
    public function rollback(): array
    {
        $this->ensureTable();
        $last = $this->db->select('SELECT MAX(batch) AS b FROM ' . $this->table);
        $batch = (int) ($last[0]['b'] ?? 0);
        if ($batch === 0) {
            return [];
        }
        $rows = $this->db->table($this->table)->where('batch', '=', $batch)->orderBy('id', 'desc')->get();
        $reverted = [];
        foreach ($rows as $row) {
            $name = (string) $row['migration'];
            $this->load($name)->down($this->db);
            $this->db->table($this->table)->where('id', '=', (int) $row['id'])->delete();
            $reverted[] = $name;
        }

        return $reverted;
    }

    /**
     * @return list<array{migration: string, applied: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $done = $this->applied();
        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[] = ['migration' => $name, 'applied' => isset($done[$name]), 'batch' => $done[$name] ?? null];
        }

        return $status;
    }

    /**
     * Drop all tables, then migrate. Destroys data: callers must refuse to run this in production.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $tables = $this->db->select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()');
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $row) {
            $this->db->execute('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');

        return $this->migrate();
    }

    private function ensureTable(): void
    {
        $this->db->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, batch INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $this->table,
        ));
    }

    /**
     * @return array<string, int> migration => batch
     */
    private function applied(): array
    {
        $result = [];
        foreach ($this->db->table($this->table)->get() as $row) {
            $result[(string) $row['migration']] = (int) $row['batch'];
        }

        return $result;
    }

    private function nextBatch(): int
    {
        $rows = $this->db->select('SELECT MAX(batch) AS b FROM ' . $this->table);

        return (int) ($rows[0]['b'] ?? 0) + 1;
    }

    /**
     * @return array<string, string> name => path, sorted by name
     */
    private function files(): array
    {
        $files = [];
        foreach (Fs::glob(rtrim($this->directory, '/') . '/*.php') as $path) {
            $files[basename($path, '.php')] = $path;
        }
        ksort($files);

        return $files;
    }

    private function load(string $name): Migration
    {
        $path = $this->files()[$name] ?? throw new RuntimeException(sprintf('Migration file for "%s" is missing.', $name));
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new RuntimeException(sprintf('Migration %s must return a Migration instance.', $name));
        }

        return $migration;
    }
}
