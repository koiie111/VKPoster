<?php

declare(strict_types=1);

namespace App\Kernel\Database;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Lazy PDO wrapper: exceptions on error, real prepared statements, utf8mb4, session time zone UTC.
 * All values go through bindings; use `QueryBuilder` (via `table()`) for dynamic queries.
 */
final class Connection
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database),
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
                ],
            );
        }

        return $this->pdo;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * @param array<array-key, scalar|null> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<array-key, scalar|null> $bindings
     * @return int affected rows
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    /**
     * Run `$callback` in a transaction (nested calls use savepoints). Commits on return, rolls back on any throwable.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $savepoint = 'sp_' . $this->depth;
        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        ++$this->depth;
        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            --$this->depth;
            if ($this->depth === 0) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
        --$this->depth;
        if ($this->depth === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return $result;
    }

    /**
     * @param array<array-key, scalar|null> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach (array_values($bindings) as $i => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();

        return $statement;
    }
}
