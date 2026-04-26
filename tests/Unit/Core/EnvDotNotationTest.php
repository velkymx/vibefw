<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Env;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L6: Env getVar() supports nested keys with dots.
 *
 * Pre-fix: The method supports dot notation for nested config arrays,
 * but environment variables with underscores (e.g. DB_HOST_NAME) cannot
 * be accessed with dot notation (db.host.name).
 *
 * Post-fix: Dots are converted to underscores when looking up environment
 * variables, allowing consistent access patterns.
 */
final class EnvDotNotationTest extends TestCase
{
    protected function setUp(): void
    {
        Env::clear();
    }

    #[Test]
    public function getVarConvertsDotsToUnderscores(): void
    {
        // Set environment variable with underscores
        putenv('DB_HOST_NAME=localhost');
        $_ENV['DB_HOST_NAME'] = 'localhost';

        // Access with dot notation
        $value = Env::get('db.host.name');

        $this->assertSame('localhost', $value);

        // Cleanup
        putenv('DB_HOST_NAME');
        unset($_ENV['DB_HOST_NAME']);
    }

    #[Test]
    public function getVarWithMultipleDots(): void
    {
        // Set environment variable with underscores
        putenv('APP_API_KEY_SECRET=secret123');
        $_ENV['APP_API_KEY_SECRET'] = 'secret123';

        // Access with dot notation
        $value = Env::get('app.api.key.secret');

        $this->assertSame('secret123', $value);

        // Cleanup
        putenv('APP_API_KEY_SECRET');
        unset($_ENV['APP_API_KEY_SECRET']);
    }

    #[Test]
    public function getVarPrefersExactMatchOverConverted(): void
    {
        // Set both exact match and converted match
        putenv('db.host=localhost');
        putenv('DB_HOST=exact');
        $_ENV['db.host'] = 'localhost';
        $_ENV['DB_HOST'] = 'exact';

        // Exact match should take precedence
        $value = Env::get('db.host');

        $this->assertSame('localhost', $value);

        // Cleanup
        putenv('db.host');
        putenv('DB_HOST');
        unset($_ENV['db.host'], $_ENV['DB_HOST']);
    }

    #[Test]
    public function getVarReturnsDefaultWhenNotFound(): void
    {
        // Try to access non-existent variable
        $value = Env::get('non.existent.key', 'default');

        $this->assertSame('default', $value);
    }

    #[Test]
    public function getStringSupportsDotNotation(): void
    {
        putenv('CACHE_DRIVER=redis');
        $_ENV['CACHE_DRIVER'] = 'redis';

        $value = Env::string('cache.driver');

        $this->assertSame('redis', $value);

        // Cleanup
        putenv('CACHE_DRIVER');
        unset($_ENV['CACHE_DRIVER']);
    }

    #[Test]
    public function getIntSupportsDotNotation(): void
    {
        putenv('QUEUE_WORKER_TIMEOUT=30');
        $_ENV['QUEUE_WORKER_TIMEOUT'] = '30';

        $value = Env::int('queue.worker.timeout');

        $this->assertSame(30, $value);

        // Cleanup
        putenv('QUEUE_WORKER_TIMEOUT');
        unset($_ENV['QUEUE_WORKER_TIMEOUT']);
    }

    #[Test]
    public function boolSupportsDotNotation(): void
    {
        putenv('APP_DEBUG=true');
        $_ENV['APP_DEBUG'] = 'true';

        $value = Env::bool('app.debug');

        $this->assertTrue($value);

        // Cleanup
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    }
}
