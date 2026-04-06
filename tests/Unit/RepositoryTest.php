<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Domain\Id;
use Fw\Domain\UserId;
use Fw\Repository\Criteria;
use Fw\Repository\EntityNotFoundException;
use Fw\Repository\InMemoryRepository;
use PHPUnit\Framework\TestCase;

// Test entity
final class TestUser
{
    public function __construct(
        public readonly UserId $id,
        public string $name,
        public string $email,
        public string $status = 'active',
        public int $age = 0
    ) {}
}

// Test repository implementation
final class InMemoryTestUserRepository extends InMemoryRepository
{
    protected function getId(mixed $entity): Id
    {
        return $entity->id;
    }

    protected function getEntityClass(): string
    {
        return TestUser::class;
    }
}

final class RepositoryTest extends TestCase
{
    private InMemoryTestUserRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryTestUserRepository();
    }

    // ==========================================
    // CRITERIA TESTS
    // ==========================================

    public function testCriteriaWhere(): void
    {
        $criteria = Criteria::create()
            ->where('status', '=', 'active');

        $conditions = $criteria->getConditions();

        $this->assertCount(1, $conditions);
        $this->assertEquals('status', $conditions[0]['field']);
        $this->assertEquals('=', $conditions[0]['operator']);
        $this->assertEquals('active', $conditions[0]['value']);
    }

    public function testCriteriaWhereEquals(): void
    {
        $criteria = Criteria::create()
            ->whereEquals('name', 'John');

        $conditions = $criteria->getConditions();

        $this->assertEquals('=', $conditions[0]['operator']);
        $this->assertEquals('John', $conditions[0]['value']);
    }

    public function testCriteriaWhereIn(): void
    {
        $criteria = Criteria::create()
            ->whereIn('status', ['active', 'pending']);

        $conditions = $criteria->getConditions();

        $this->assertEquals('IN', $conditions[0]['operator']);
        $this->assertEquals(['active', 'pending'], $conditions[0]['value']);
    }

    public function testCriteriaWhereNull(): void
    {
        $criteria = Criteria::create()
            ->whereNull('deleted_at');

        $conditions = $criteria->getConditions();

        $this->assertEquals('IS', $conditions[0]['operator']);
        $this->assertNull($conditions[0]['value']);
    }

    public function testCriteriaWhereLike(): void
    {
        $criteria = Criteria::create()
            ->whereLike('name', 'John%');

        $conditions = $criteria->getConditions();

        $this->assertEquals('LIKE', $conditions[0]['operator']);
        $this->assertEquals('John%', $conditions[0]['value']);
    }

    public function testCriteriaOrderBy(): void
    {
        $criteria = Criteria::create()
            ->orderBy('name', 'asc')
            ->orderByDesc('created_at');

        $orderBy = $criteria->getOrderBy();

        $this->assertCount(2, $orderBy);
        $this->assertEquals('name', $orderBy[0]['field']);
        $this->assertEquals('asc', $orderBy[0]['direction']);
        $this->assertEquals('created_at', $orderBy[1]['field']);
        $this->assertEquals('desc', $orderBy[1]['direction']);
    }

    public function testCriteriaLimitOffset(): void
    {
        $criteria = Criteria::create()
            ->limit(10)
            ->offset(20);

        $this->assertEquals(10, $criteria->getLimit());
        $this->assertEquals(20, $criteria->getOffset());
    }

    public function testCriteriaInclude(): void
    {
        $criteria = Criteria::create()
            ->include('posts', 'comments');

        $this->assertEquals(['posts', 'comments'], $criteria->getIncludes());
    }

    public function testCriteriaIsImmutable(): void
    {
        $criteria1 = Criteria::create();
        $criteria2 = $criteria1->where('name', '=', 'John');

        $this->assertNotSame($criteria1, $criteria2);
        $this->assertEmpty($criteria1->getConditions());
        $this->assertCount(1, $criteria2->getConditions());
    }

    public function testCriteriaHasConditions(): void
    {
        $empty = Criteria::create();
        $withCondition = Criteria::create()->where('name', '=', 'John');

        $this->assertFalse($empty->hasConditions());
        $this->assertTrue($withCondition->hasConditions());
    }

    // ==========================================
    // IN MEMORY REPOSITORY TESTS
    // ==========================================

    public function testSaveAndFind(): void
    {
        $user = new TestUser(UserId::generate(), 'John', 'john@example.com');

        $this->repository->save($user);
        $found = $this->repository->find($user->id);

        $this->assertTrue($found->isSome());
        $this->assertSame($user, $found->unwrapOr(null));
    }

    public function testFindReturnsNoneForMissing(): void
    {
        $found = $this->repository->find(UserId::generate());

        $this->assertTrue($found->isNone());
    }

    public function testFindOrFailReturnsEntity(): void
    {
        $user = new TestUser(UserId::generate(), 'John', 'john@example.com');
        $this->repository->save($user);

        $found = $this->repository->findOrFail($user->id);

        $this->assertSame($user, $found);
    }

    public function testFindOrFailThrowsForMissing(): void
    {
        $id = UserId::generate();

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage("TestUser with ID {$id} not found");

        $this->repository->findOrFail($id);
    }

    public function testExists(): void
    {
        $user = new TestUser(UserId::generate(), 'John', 'john@example.com');

        $this->assertFalse($this->repository->exists($user->id));

        $this->repository->save($user);

        $this->assertTrue($this->repository->exists($user->id));
    }

    public function testDelete(): void
    {
        $user = new TestUser(UserId::generate(), 'John', 'john@example.com');
        $this->repository->save($user);

        $this->repository->delete($user);

        $this->assertFalse($this->repository->exists($user->id));
    }

    public function testDeleteById(): void
    {
        $user = new TestUser(UserId::generate(), 'John', 'john@example.com');
        $this->repository->save($user);

        $this->repository->deleteById($user->id);

        $this->assertFalse($this->repository->exists($user->id));
    }

    public function testAll(): void
    {
        $user1 = new TestUser(UserId::generate(), 'John', 'john@example.com');
        $user2 = new TestUser(UserId::generate(), 'Jane', 'jane@example.com');

        $this->repository->save($user1);
        $this->repository->save($user2);

        $all = $this->repository->all();

        $this->assertCount(2, $all);
    }

    public function testCount(): void
    {
        $this->assertEquals(0, $this->repository->count());

        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com'));

        $this->assertEquals(2, $this->repository->count());
    }

    public function testClear(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com'));

        $this->repository->clear();

        $this->assertEquals(0, $this->repository->count());
    }

    // ==========================================
    // FIND BY CRITERIA TESTS
    // ==========================================

    public function testFindByCriteriaWithEquality(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com', 'active'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com', 'inactive'));
        $this->repository->save(new TestUser(UserId::generate(), 'Bob', 'bob@example.com', 'active'));

        $criteria = Criteria::create()->whereEquals('status', 'active');
        $results = $this->repository->findByCriteria($criteria);

        $this->assertCount(2, $results);
        $this->assertEquals('John', $results[0]->name);
        $this->assertEquals('Bob', $results[1]->name);
    }

    public function testFindByCriteriaWithComparison(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'Young', 'young@example.com', 'active', 20));
        $this->repository->save(new TestUser(UserId::generate(), 'Middle', 'middle@example.com', 'active', 35));
        $this->repository->save(new TestUser(UserId::generate(), 'Old', 'old@example.com', 'active', 50));

        $criteria = Criteria::create()->where('age', '>=', 35);
        $results = $this->repository->findByCriteria($criteria);

        $this->assertCount(2, $results);
    }

    public function testFindByCriteriaWithIn(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com', 'active'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com', 'pending'));
        $this->repository->save(new TestUser(UserId::generate(), 'Bob', 'bob@example.com', 'deleted'));

        $criteria = Criteria::create()->whereIn('status', ['active', 'pending']);
        $results = $this->repository->findByCriteria($criteria);

        $this->assertCount(2, $results);
    }

    public function testFindByCriteriaWithLike(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John Smith', 'john@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'John Doe', 'johndoe@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane Doe', 'jane@example.com'));

        $criteria = Criteria::create()->whereLike('name', 'John%');
        $results = $this->repository->findByCriteria($criteria);

        $this->assertCount(2, $results);
    }

    public function testFindByCriteriaWithOrdering(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'Charlie', 'charlie@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Alice', 'alice@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Bob', 'bob@example.com'));

        $criteria = Criteria::create()->orderBy('name', 'asc');
        $results = $this->repository->findByCriteria($criteria);

        $this->assertEquals('Alice', $results[0]->name);
        $this->assertEquals('Bob', $results[1]->name);
        $this->assertEquals('Charlie', $results[2]->name);
    }

    public function testFindByCriteriaWithDescOrdering(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'Young', 'young@example.com', 'active', 20));
        $this->repository->save(new TestUser(UserId::generate(), 'Old', 'old@example.com', 'active', 50));
        $this->repository->save(new TestUser(UserId::generate(), 'Middle', 'middle@example.com', 'active', 35));

        $criteria = Criteria::create()->orderByDesc('age');
        $results = $this->repository->findByCriteria($criteria);

        $this->assertEquals(50, $results[0]->age);
        $this->assertEquals(35, $results[1]->age);
        $this->assertEquals(20, $results[2]->age);
    }

    public function testFindByCriteriaWithLimitOffset(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->repository->save(new TestUser(
                UserId::generate(),
                "User {$i}",
                "user{$i}@example.com"
            ));
        }

        $criteria = Criteria::create()->limit(3)->offset(2);
        $results = $this->repository->findByCriteria($criteria);

        $this->assertCount(3, $results);
    }

    public function testFindOneByCriteria(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com'));

        $criteria = Criteria::create()->whereEquals('name', 'John');
        $result = $this->repository->findOneByCriteria($criteria);

        $this->assertTrue($result->isSome());
        $this->assertEquals('John', $result->unwrapOr(null)->name);
    }

    public function testFindOneByCriteriaReturnsNone(): void
    {
        $criteria = Criteria::create()->whereEquals('name', 'NonExistent');
        $result = $this->repository->findOneByCriteria($criteria);

        $this->assertTrue($result->isNone());
    }

    public function testCountByCriteria(): void
    {
        $this->repository->save(new TestUser(UserId::generate(), 'John', 'john@example.com', 'active'));
        $this->repository->save(new TestUser(UserId::generate(), 'Jane', 'jane@example.com', 'inactive'));
        $this->repository->save(new TestUser(UserId::generate(), 'Bob', 'bob@example.com', 'active'));

        $criteria = Criteria::create()->whereEquals('status', 'active');

        $this->assertEquals(2, $this->repository->countByCriteria($criteria));
    }

    // ==========================================
    // ENTITY NOT FOUND EXCEPTION TESTS
    // ==========================================

    public function testEntityNotFoundExceptionMessage(): void
    {
        $id = UserId::generate();
        $exception = EntityNotFoundException::for(TestUser::class, $id);

        $this->assertEquals(TestUser::class, $exception->entityClass);
        $this->assertSame($id, $exception->id);
        $this->assertStringContainsString('TestUser', $exception->getMessage());
        $this->assertStringContainsString($id->value, $exception->getMessage());
    }
}
