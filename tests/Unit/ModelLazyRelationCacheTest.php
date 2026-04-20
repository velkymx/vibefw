<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Model\BelongsTo;
use Fw\Model\HasMany;
use Fw\Model\Model;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LazyCacheUser extends Model
{
    protected static ?string $table = 'lazy_users';
    protected static array $fillable = ['name'];
    protected static bool $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(LazyCachePost::class, 'user_id', 'id');
    }
}

class LazyCachePost extends Model
{
    protected static ?string $table = 'lazy_posts';
    protected static array $fillable = ['user_id', 'title'];
    protected static bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(LazyCacheUser::class, 'user_id', 'id');
    }
}

final class ModelLazyRelationCacheTest extends TestCase
{
    protected ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'logging' => true,
        ]);

        Model::setConnection($this->db);

        $this->db->query('CREATE TABLE lazy_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->db->query('CREATE TABLE lazy_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');

        $this->db->insert('lazy_users', ['name' => 'Alice']);
        $this->db->insert('lazy_posts', ['user_id' => 1, 'title' => 'First']);
        $this->db->insert('lazy_posts', ['user_id' => 1, 'title' => 'Second']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Connection::reset();
    }

    private function countPostSelects(int $before): int
    {
        $log = array_slice($this->db->getQueryLog(), $before);
        return count(array_filter(
            $log,
            fn (array $entry) => str_contains($entry['sql'], 'lazy_posts') && str_starts_with(ltrim($entry['sql']), 'SELECT'),
        ));
    }

    #[Test]
    public function lazyLoadedRelationIsCachedAfterFirstAccess(): void
    {
        $user = LazyCacheUser::find(1)->unwrapOr(null);
        $checkpoint = count($this->db->getQueryLog());

        $first = $user->posts;
        $second = $user->posts;

        $this->assertCount(2, $first);
        $this->assertSame($first, $second, 'Second access must return the cached collection, not a fresh fetch.');
        $this->assertSame(1, $this->countPostSelects($checkpoint), 'Lazy relation must issue exactly one SELECT on repeated access.');
    }

    #[Test]
    public function relationsArrayKeyedConsistentlyAcrossAccessors(): void
    {
        $user = LazyCacheUser::find(1)->unwrapOr(null);
        $user->posts; // trigger lazy load

        $checkpoint = count($this->db->getQueryLog());
        // Access via multiple accessor paths — all must hit the cache.
        $user->getAttribute('posts');
        $user->posts;

        $this->assertSame(0, $this->countPostSelects($checkpoint), 'Additional accessor calls must not re-issue the relation query.');
    }
}
