<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Database\Connection;
use App\Kernel\Database\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identifier and operator whitelisting; no connection is opened here.
 */
#[CoversClass(QueryBuilder::class)]
final class QueryBuilderTest extends TestCase
{
    private function db(): Connection
    {
        return new Connection('unused', 3306, 'unused', 'u', 'p');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badIdentifiers(): array
    {
        return [
            'injection' => ['id; DROP TABLE users'],
            'quote' => ["id'"],
            'comment' => ['id--'],
            'space' => ['id desc'],
            'backtick' => ['`id`'],
            'empty' => [''],
            'leading digit' => ['1abc'],
            'three parts' => ['a.b.c'],
        ];
    }

    #[DataProvider('badIdentifiers')]
    public function testBadColumnNamesAreRejected(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueryBuilder($this->db(), 'users'))->where($column, '=', 1);
    }

    #[DataProvider('badIdentifiers')]
    public function testBadOrderColumnsAreRejected(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueryBuilder($this->db(), 'users'))->orderBy($column);
    }

    public function testBadTableNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueryBuilder($this->db(), 'users; --');
    }

    public function testOperatorAndDirectionAreWhitelisted(): void
    {
        $builder = new QueryBuilder($this->db(), 'users');

        try {
            $builder->where('id', '= 1 OR 1=1 --', 1);
            self::fail('operator must be rejected');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $builder->orderBy('id', 'asc; DROP');
    }

    public function testNullComparisonMustUseDedicatedMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueryBuilder($this->db(), 'users'))->where('id', '=', null);
    }

    public function testDeleteWithoutWhereIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QueryBuilder($this->db(), 'users'))->delete();
    }

    public function testEmptyInsertAndUpdateAreRefused(): void
    {
        $builder = new QueryBuilder($this->db(), 'users');
        try {
            $builder->insert([]);
            self::fail('empty insert');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $builder->update([]);
    }
}
