<?php

declare(strict_types=1);

namespace App\Kernel\Database;

use InvalidArgumentException;

/**
 * Minimal query builder. Values are always bound; table and column names must match a strict
 * identifier pattern and operators/directions come from a whitelist, so user input can never
 * become SQL text. No raw SQL fragments are accepted.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{string, string, scalar|null}|array{string, string, list<scalar|null>}|array{string, string}> */
    private array $wheres = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private bool $lock = false;
    private bool $skipLocked = false;

    public function __construct(private readonly Connection $connection, private readonly string $table)
    {
        self::assertIdentifier($table);
    }

    /**
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }
        $this->columns = $columns;

        return $this;
    }

    /**
     * @param scalar|null $value
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        self::assertIdentifier($column);
        $operator = strtoupper($operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Operator is not allowed.');
        }
        if ($value === null) {
            throw new InvalidArgumentException('Use whereNull()/whereNotNull() to compare with NULL.');
        }
        $this->wheres[] = [$column, $operator, $value];

        return $this;
    }

    /**
     * @param list<scalar|null> $values
     */
    public function whereIn(string $column, array $values): self
    {
        self::assertIdentifier($column);
        $this->wheres[] = [$column, 'IN', $values];

        return $this;
    }

    public function whereNull(string $column): self
    {
        self::assertIdentifier($column);
        $this->wheres[] = [$column, 'IS NULL'];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        self::assertIdentifier($column);
        $this->wheres[] = [$column, 'IS NOT NULL'];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        self::assertIdentifier($column);
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        $this->orders[] = $column . ' ' . $direction;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * `FOR UPDATE` (only meaningful inside a transaction).
     */
    public function forUpdate(): self
    {
        $this->lock = true;

        return $this;
    }

    /**
     * `FOR UPDATE SKIP LOCKED`: rows locked by other transactions are skipped (job queues).
     */
    public function skipLocked(): self
    {
        $this->lock = true;
        $this->skipLocked = true;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table . $where;
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }
        if ($this->lock) {
            $sql .= $this->skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
        }

        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function count(): int
    {
        [$where, $bindings] = $this->compileWhere();
        $rows = $this->connection->select('SELECT COUNT(*) AS c FROM ' . $this->table . $where, $bindings);

        return (int) ($rows[0]['c'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * @param array<string, scalar|null> $values column => value
     * @return string last insert id (0 for tables without auto-increment)
     */
    public function insert(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Nothing to insert.');
        }
        foreach (array_keys($values) as $column) {
            self::assertIdentifier($column);
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        );
        $this->connection->execute($sql, array_values($values));

        return $this->connection->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $values column => new value
     * @return int affected rows
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('Nothing to update.');
        }
        foreach (array_keys($values) as $column) {
            self::assertIdentifier($column);
        }
        [$where, $bindings] = $this->compileWhere();
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));

        return $this->connection->execute(
            'UPDATE ' . $this->table . ' SET ' . $sets . $where,
            [...array_values($values), ...$bindings],
        );
    }

    /**
     * @return int affected rows
     */
    public function delete(): int
    {
        [$where, $bindings] = $this->compileWhere();
        if ($where === '') {
            throw new InvalidArgumentException('Refusing to DELETE without a WHERE clause.');
        }

        return $this->connection->execute('DELETE FROM ' . $this->table . $where, $bindings);
    }

    /**
     * @return array{string, list<scalar|null>}
     */
    private function compileWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }
        $parts = [];
        $bindings = [];
        foreach ($this->wheres as $where) {
            [$column, $operator] = $where;
            if (count($where) === 2) {
                $parts[] = $column . ' ' . $operator;
            } elseif ($operator === 'IN') {
                /** @var list<scalar|null> $list */
                $list = $where[2];
                if ($list === []) {
                    $parts[] = '1 = 0';
                    continue;
                }
                $parts[] = $column . ' IN (' . implode(', ', array_fill(0, count($list), '?')) . ')';
                array_push($bindings, ...$list);
            } else {
                $parts[] = $column . ' ' . $operator . ' ?';
                /** @var scalar $value */
                $value = $where[2];
                $bindings[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $parts), $bindings];
    }

    private static function assertIdentifier(string $name): void
    {
        if ($name !== '*' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
    }
}
