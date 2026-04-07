<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Fw\Tests\TestCase;

/**
 * QueryBuilder state validation — prevents calling terminal methods
 * without required state being set first.
 */
final class QueryBuilderStateTest extends TestCase
{
    private QueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->createDatabase();
        $this->builder = new QueryBuilder($connection);
    }

    #[Test]
    public function getThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->get();
    }

    #[Test]
    public function firstThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->first();
    }

    #[Test]
    public function countThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->count();
    }

    #[Test]
    public function deleteThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->delete();
    }

    #[Test]
    public function updateThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->update(['name' => 'test']);
    }

    #[Test]
    public function insertThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->insert(['name' => 'test']);
    }

    #[Test]
    public function toSqlThrowsWithoutTable(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('table');

        $this->builder->toSql();
    }

    #[Test]
    public function joinThrowsAfterLimit(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('join');

        $this->builder->table('users')->limit(10)->join('posts', 'users.id', '=', 'posts.user_id');
    }

    #[Test]
    public function leftJoinThrowsAfterLimit(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('join');

        $this->builder->table('users')->limit(10)->leftJoin('posts', 'users.id', '=', 'posts.user_id');
    }
}
