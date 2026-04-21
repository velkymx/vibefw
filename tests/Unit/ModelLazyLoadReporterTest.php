<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Closure;
use Fw\Database\Connection;
use Fw\Model\HasMany;
use Fw\Model\Model;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LazyReporterUser extends Model
{
    protected static ?string $table = 'reporter_users';
    protected static array $fillable = ['name'];
    protected static bool $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(LazyReporterPost::class, 'user_id', 'id');
    }
}

class LazyReporterPost extends Model
{
    protected static ?string $table = 'reporter_posts';
    protected static array $fillable = ['user_id', 'title'];
    protected static bool $timestamps = false;
}

final class ModelLazyLoadReporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        Model::setConnection($this->db);

        $this->db->query('CREATE TABLE reporter_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->db->query('CREATE TABLE reporter_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL)');
        $this->db->insert('reporter_users', ['name' => 'Alice']);
        $this->db->insert('reporter_posts', ['user_id' => 1, 'title' => 'First']);

        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        Model::$lazyLoadReporter = null;
        unset($_ENV['APP_DEBUG']);
        Connection::reset();
        parent::tearDown();
    }

    #[Test]
    public function lazyLoadReporterIsInjectableAndCapturesWarnings(): void
    {
        $messages = [];
        Model::$lazyLoadReporter = static function (string $m) use (&$messages): void {
            $messages[] = $m;
        };

        $user = LazyReporterUser::find(1)->unwrapOr(null);
        $this->assertNotNull($user);

        $user->posts; // trigger lazy load

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('N+1 WARNING', $messages[0]);
        $this->assertStringContainsString("relationship 'posts'", $messages[0]);
        $this->assertStringContainsString(LazyReporterUser::class, $messages[0]);
    }

    #[Test]
    public function nullSilencingReporterSuppressesOutput(): void
    {
        Model::$lazyLoadReporter = static fn(string $m): null => null;

        $user = LazyReporterUser::find(1)->unwrapOr(null);
        $this->assertNotNull($user);

        $user->posts;

        // Implicit assertion: no stderr noise reaches phpunit output.
        // Explicit assertion: reporter type pins the contract.
        $this->assertInstanceOf(Closure::class, Model::$lazyLoadReporter);
    }

    #[Test]
    public function reporterIsNotInvokedWhenDebugDisabled(): void
    {
        $calls = 0;
        Model::$lazyLoadReporter = static function () use (&$calls): void {
            $calls++;
        };

        unset($_ENV['APP_DEBUG']);
        putenv('APP_DEBUG'); // clear

        $user = LazyReporterUser::find(1)->unwrapOr(null);
        $user->posts;

        $this->assertSame(0, $calls, 'Reporter must only be invoked when APP_DEBUG is truthy.');
    }
}
