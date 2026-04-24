<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Validation\Rules\Exists;
use Fw\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item #52: `Validator::resolveDbRule()` resolved the DB via
 * `Connection::getInstance()` — a static singleton. In worker /
 * Fiber mode two concurrent requests share the same PHP process,
 * so a singleton pinned at bootstrap cannot be swapped per
 * request, and `::resetRequestState()` hygiene on the framework's
 * own Connection instance does not extend to whatever the
 * Validator reached for.
 *
 * [R56] lets `Validator::__construct()` accept an optional
 * `?Connection` dependency. When present, `resolveDbRule()` uses
 * it. When absent (legacy callers), it still falls back to
 * `Connection::getInstance()` so existing code is unaffected.
 *
 * Tests verify:
 *   - Constructor accepts an optional ?Connection parameter
 *   - Source of resolveDbRule() prefers the injected connection
 *     (references `$this->connection`)
 *   - Injected Connection is actually used when present (observed
 *     via a real SQLite in-memory Connection + Exists rule against
 *     a populated table).
 */
final class ValidatorConnectionInjectionTest extends TestCase
{
    #[Test]
    public function constructorAcceptsOptionalConnection(): void
    {
        $ctor = new ReflectionMethod(Validator::class, '__construct');
        $params = $ctor->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('connection', $params[0]->getName());
        $this->assertTrue($params[0]->allowsNull());
        $this->assertTrue($params[0]->isOptional());

        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertSame('?' . Connection::class, (string) $type);
    }

    #[Test]
    public function resolveDbRuleSourcePrefersInjectedConnection(): void
    {
        $rm = new ReflectionMethod(Validator::class, 'resolveDbRule');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertMatchesRegularExpression(
            '/\$this->connection/',
            $body,
            'resolveDbRule must prefer the injected connection before falling back'
        );
    }

    #[Test]
    public function injectedConnectionIsUsedForDatabaseRule(): void
    {
        // Build a real in-memory SQLite connection and bootstrap a
        // 'slugs' table so Exists has something to hit. If the
        // Validator were still pinned to Connection::getInstance(),
        // the rule would fall through to a disconnected singleton
        // and fail — so the test is a live smoke test of the
        // injection, not just a reflection check.
        $connection = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $connection->query('CREATE TABLE slugs (id INTEGER PRIMARY KEY, value TEXT UNIQUE)');
        $connection->query("INSERT INTO slugs (value) VALUES ('live')");

        $validator = new Validator($connection);

        $errors = $validator->validate(
            ['slug' => 'live'],
            ['slug' => [new Exists('slugs', 'value')]]
        );

        $this->assertSame(['slug' => 'live'], $errors);
    }

    #[Test]
    public function noArgConstructorStillFallsBackToSingleton(): void
    {
        // Creating Validator() without an injected connection must not
        // throw. The actual resolveDbRule call is gated by whether a
        // DatabaseRule is used, so we only verify the object
        // constructs and has a nullable `connection` property.
        $validator = new Validator();
        $prop = (new ReflectionClass($validator))->getProperty('connection');
        $this->assertNull($prop->getValue($validator));
    }
}
