<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Model\Collection;
use Fw\Model\HasMany;
use Fw\Model\HasOne;
use Fw\Model\BelongsTo;
use Fw\Model\Model;
use Fw\Model\ModelNotFoundException;
use Fw\Support\Option;
use Fw\Tests\TestCase;

// Test Model classes
class ModelTestUser extends Model
{
    protected static ?string $table = 'users';
    protected static array $fillable = ['name', 'email', 'is_active'];
    protected static array $casts = ['is_active' => 'bool'];
    protected static bool $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(ModelTestPost::class, 'user_id', 'id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ModelTestProfile::class, 'user_id', 'id');
    }

    public static function active(): \Fw\Model\ModelQueryBuilder
    {
        return static::where('is_active', true);
    }
}

class ModelTestPost extends Model
{
    protected static ?string $table = 'posts';
    protected static array $fillable = ['user_id', 'title', 'body'];
    protected static bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(ModelTestUser::class, 'user_id', 'id');
    }
}

class ModelTestProfile extends Model
{
    protected static ?string $table = 'profiles';
    protected static array $fillable = ['user_id', 'bio'];
    protected static bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(ModelTestUser::class, 'user_id', 'id');
    }
}

final class ModelTest extends TestCase
{
    protected ?Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset any existing connection first
        Connection::reset();

        // Create in-memory SQLite database
        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        Model::setConnection($this->db);

        // Create test tables
        $this->db->query('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            is_active INTEGER DEFAULT 1
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            bio TEXT
        )');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Connection::reset();
    }

    // ========================================
    // BASIC CRUD TESTS
    // ========================================

    public function testCanCreateModel(): void
    {
        $user = ModelTestUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertTrue($user->exists);
        $this->assertNotNull($user->getKey());
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testCanFindModel(): void
    {
        $created = ModelTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $found = ModelTestUser::find($created->getKey());

        $this->assertTrue($found->isSome());
        $this->assertEquals('Jane', $found->unwrapOr(null)->name);
    }

    public function testFindReturnsNoneForMissing(): void
    {
        $found = ModelTestUser::find(9999);

        $this->assertTrue($found->isNone());
    }

    public function testFindOrFailThrowsForMissing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        ModelTestUser::findOrFail(9999);
    }

    public function testCanUpdateModel(): void
    {
        $user = ModelTestUser::create(['name' => 'Original', 'email' => 'test@example.com']);

        $user->name = 'Updated';
        (void) $user->save();

        $fresh = ModelTestUser::find($user->getKey())->unwrapOr(null);
        $this->assertEquals('Updated', $fresh->name);
    }

    public function testCanDeleteModel(): void
    {
        $user = ModelTestUser::create(['name' => 'ToDelete', 'email' => 'delete@example.com']);
        $id = $user->getKey();

        (void) $user->delete();

        $this->assertTrue(ModelTestUser::find($id)->isNone());
    }

    public function testDestroyDeletesMultiple(): void
    {
        $user1 = ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        $user2 = ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $count = ModelTestUser::destroy([$user1->getKey(), $user2->getKey()]);

        $this->assertEquals(2, $count);
        $this->assertTrue(ModelTestUser::find($user1->getKey())->isNone());
        $this->assertTrue(ModelTestUser::find($user2->getKey())->isNone());
    }

    // ========================================
    // QUERY BUILDER TESTS
    // ========================================

    public function testWhereQuery(): void
    {
        (void) ModelTestUser::create(['name' => 'Active', 'email' => 'a@example.com', 'is_active' => true]);
        (void) ModelTestUser::create(['name' => 'Inactive', 'email' => 'b@example.com', 'is_active' => false]);

        $active = ModelTestUser::where('is_active', true)->get();

        $this->assertCount(1, $active);
        $this->assertEquals('Active', $active->first()->unwrapOr(null)->name);
    }

    public function testAllReturnsCollection(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $all = ModelTestUser::all();

        $this->assertInstanceOf(Collection::class, $all);
        $this->assertCount(2, $all);
    }

    public function testFirstReturnsOption(): void
    {
        (void) ModelTestUser::create(['name' => 'First', 'email' => 'first@example.com']);

        $first = ModelTestUser::first();

        $this->assertTrue($first->isSome());
        $this->assertEquals('First', $first->unwrapOr(null)->name);
    }

    public function testOrderBy(): void
    {
        (void) ModelTestUser::create(['name' => 'Zebra', 'email' => 'z@example.com']);
        (void) ModelTestUser::create(['name' => 'Alpha', 'email' => 'a@example.com']);

        $ordered = ModelTestUser::orderBy('name', 'ASC')->get();

        $this->assertEquals('Alpha', $ordered->first()->unwrapOr(null)->name);
    }

    public function testLimit(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);
        (void) ModelTestUser::create(['name' => 'User 3', 'email' => 'u3@example.com']);

        $limited = ModelTestUser::limit(2)->get();

        $this->assertCount(2, $limited);
    }

    public function testCount(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $count = ModelTestUser::count();

        $this->assertEquals(2, $count);
    }

    public function testExists(): void
    {
        $this->assertFalse(ModelTestUser::exists());

        (void) ModelTestUser::create(['name' => 'User', 'email' => 'u@example.com']);

        $this->assertTrue(ModelTestUser::exists());
    }

    public function testPluck(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $names = ModelTestUser::pluck('name');

        $this->assertCount(2, $names);
        $this->assertContains('User 1', $names);
        $this->assertContains('User 2', $names);
    }

    public function testQueryScope(): void
    {
        (void) ModelTestUser::create(['name' => 'Active', 'email' => 'a@example.com', 'is_active' => true]);
        (void) ModelTestUser::create(['name' => 'Inactive', 'email' => 'b@example.com', 'is_active' => false]);

        $active = ModelTestUser::active()->get();

        $this->assertCount(1, $active);
    }

    // ========================================
    // RELATIONSHIP TESTS
    // ========================================

    public function testHasManyRelationship(): void
    {
        $user = ModelTestUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        (void) ModelTestPost::create(['user_id' => $user->getKey(), 'title' => 'Post 1', 'body' => 'Body 1']);
        (void) ModelTestPost::create(['user_id' => $user->getKey(), 'title' => 'Post 2', 'body' => 'Body 2']);

        $posts = $user->posts()->get();

        $this->assertCount(2, $posts);
    }

    public function testBelongsToRelationship(): void
    {
        $user = ModelTestUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post = ModelTestPost::create(['user_id' => $user->getKey(), 'title' => 'Post', 'body' => 'Body']);

        $author = $post->user()->get();

        $this->assertTrue($author->isSome());
        $this->assertEquals('Author', $author->unwrapOr(null)->name);
    }

    public function testHasOneRelationship(): void
    {
        $user = ModelTestUser::create(['name' => 'User', 'email' => 'user@example.com']);
        (void) ModelTestProfile::create(['user_id' => $user->getKey(), 'bio' => 'Hello world']);

        $profile = $user->profile()->get();

        $this->assertTrue($profile->isSome());
        $this->assertEquals('Hello world', $profile->unwrapOr(null)->bio);
    }

    public function testEagerLoading(): void
    {
        $user = ModelTestUser::create(['name' => 'Author', 'email' => 'author@example.com']);
        (void) ModelTestPost::create(['user_id' => $user->getKey(), 'title' => 'Post 1', 'body' => 'Body']);

        // This would normally do eager loading
        $users = ModelTestUser::with('posts')->get();

        $this->assertCount(1, $users);
    }

    // ========================================
    // CASTING TESTS
    // ========================================

    public function testBooleanCasting(): void
    {
        $user = ModelTestUser::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'is_active' => 1,
        ]);

        $found = ModelTestUser::find($user->getKey())->unwrapOr(null);

        $this->assertIsBool($found->is_active);
        $this->assertTrue($found->is_active);
    }

    // ========================================
    // DIRTY TRACKING TESTS
    // ========================================

    public function testIsDirty(): void
    {
        $user = ModelTestUser::create(['name' => 'Original', 'email' => 'test@example.com']);

        $this->assertFalse($user->isDirty());

        $user->name = 'Changed';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    public function testGetDirty(): void
    {
        $user = ModelTestUser::create(['name' => 'Original', 'email' => 'test@example.com']);

        $user->name = 'Changed';

        $dirty = $user->getDirty();

        $this->assertArrayHasKey('name', $dirty);
        $this->assertEquals('Changed', $dirty['name']);
    }

    // ========================================
    // COLLECTION TESTS
    // ========================================

    public function testCollectionMap(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $names = ModelTestUser::all()->map(fn($u) => $u->name);

        $this->assertEquals(['User 1', 'User 2'], $names->all());
    }

    public function testCollectionFilter(): void
    {
        (void) ModelTestUser::create(['name' => 'Active', 'email' => 'a@example.com', 'is_active' => true]);
        (void) ModelTestUser::create(['name' => 'Inactive', 'email' => 'b@example.com', 'is_active' => false]);

        $active = ModelTestUser::all()->filter(fn($u) => $u->is_active);

        $this->assertCount(1, $active);
    }

    public function testCollectionFirst(): void
    {
        (void) ModelTestUser::create(['name' => 'First', 'email' => 'first@example.com']);

        $first = ModelTestUser::all()->first();

        $this->assertTrue($first->isSome());
    }

    public function testCollectionPluck(): void
    {
        (void) ModelTestUser::create(['name' => 'User 1', 'email' => 'u1@example.com']);
        (void) ModelTestUser::create(['name' => 'User 2', 'email' => 'u2@example.com']);

        $emails = ModelTestUser::all()->pluck('email');

        $this->assertCount(2, $emails);
    }

    // ========================================
    // UPDATE OR CREATE TESTS
    // ========================================

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        (void) ModelTestUser::create(['name' => 'Original', 'email' => 'test@example.com']);

        $user = ModelTestUser::updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Updated']
        );

        $this->assertEquals('Updated', $user->name);
        $this->assertEquals(1, ModelTestUser::count());
    }

    public function testUpdateOrCreateCreatesNew(): void
    {
        $user = ModelTestUser::updateOrCreate(
            ['email' => 'new@example.com'],
            ['name' => 'New User']
        );

        $this->assertEquals('New User', $user->name);
        $this->assertTrue($user->exists);
    }

    public function testFirstOrCreateReturnsExisting(): void
    {
        $original = ModelTestUser::create(['name' => 'Original', 'email' => 'test@example.com']);

        $found = ModelTestUser::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Should Not Use']
        );

        $this->assertEquals($original->getKey(), $found->getKey());
        $this->assertEquals('Original', $found->name);
    }

    public function testFirstOrCreateCreatesNew(): void
    {
        $user = ModelTestUser::firstOrCreate(
            ['email' => 'new@example.com'],
            ['name' => 'New User']
        );

        $this->assertEquals('New User', $user->name);
        $this->assertTrue($user->exists);
    }
}
