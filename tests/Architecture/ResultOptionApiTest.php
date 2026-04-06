<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verify Result and Option have the correct v3 API surface:
 * - Removed methods don't exist
 * - match() uses named parameters
 */
final class ResultOptionApiTest extends TestCase
{
    /**
     * Methods that must NOT exist.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function removedMethodsProvider(): array
    {
        return [
            'Result::unwrap'    => [\Fw\Support\Result::class, 'unwrap'],
            'Result::getValue'  => [\Fw\Support\Result::class, 'getValue'],
            'Result::getError'  => [\Fw\Support\Result::class, 'getError'],
            'Option::unwrap'    => [\Fw\Support\Option::class, 'unwrap'],
        ];
    }

    #[Test]
    #[DataProvider('removedMethodsProvider')]
    public function removedMethodDoesNotExist(string $class, string $method): void
    {
        $ref = new ReflectionClass($class);

        $this->assertFalse(
            $ref->hasMethod($method),
            "{$class}::{$method}() must be removed in v3"
        );
    }

    #[Test]
    public function resultMatchUsesNamedParameters(): void
    {
        $ref = new ReflectionMethod(\Fw\Support\Result::class, 'match');
        $params = $ref->getParameters();

        $this->assertSame('ok', $params[0]->getName());
        $this->assertSame('err', $params[1]->getName());
    }

    #[Test]
    public function optionMatchUsesNamedParameters(): void
    {
        $ref = new ReflectionMethod(\Fw\Support\Option::class, 'match');
        $params = $ref->getParameters();

        $this->assertSame('some', $params[0]->getName());
        $this->assertSame('none', $params[1]->getName());
    }

    #[Test]
    public function resultMatchWorksWithNamedArgs(): void
    {
        $ok = \Fw\Support\Result::ok(42);
        $result = $ok->match(
            ok: fn($v) => $v * 2,
            err: fn($e) => 0,
        );
        $this->assertSame(84, $result);

        $err = \Fw\Support\Result::err('fail');
        $result = $err->match(
            ok: fn($v) => 0,
            err: fn($e) => $e,
        );
        $this->assertSame('fail', $result);
    }

    #[Test]
    public function optionMatchWorksWithNamedArgs(): void
    {
        $some = \Fw\Support\Option::some(42);
        $result = $some->match(
            some: fn($v) => $v * 2,
            none: fn() => 0,
        );
        $this->assertSame(84, $result);

        $none = \Fw\Support\Option::none();
        $result = $none->match(
            some: fn($v) => 0,
            none: fn() => -1,
        );
        $this->assertSame(-1, $result);
    }
}
