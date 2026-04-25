<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Model;

use Fw\Model\Castable;
use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H13 — castToClass() must require the Castable interface for the
 * constructor fallback. The old code allowed any Fw\ or App\ class to
 * be instantiated via `new $class($value)`, which expands the attack
 * surface if a cast class name is ever sourced from untrusted input.
 */
final class ModelCastableGuardTest extends TestCase
{
    #[Test]
    public function castToClassRequiresCastableInterfaceForConstructorFallback(): void
    {
        $body = $this->methodBody('castToClass');

        $this->assertStringContainsString(
            'Castable::class',
            $body,
            'castToClass() must require the Castable interface for the constructor fallback instead of checking namespace prefixes.',
        );
    }

    #[Test]
    public function castToClassDoesNotUseNamespacePrefixGuard(): void
    {
        $body = $this->methodBody('castToClass');

        $this->assertStringNotContainsString(
            "str_starts_with(\$class, 'Fw\\\\')",
            $body,
            'castToClass() must not use namespace prefix checks — use the Castable interface instead.',
        );
    }

    #[Test]
    public function castableInterfaceExists(): void
    {
        $this->assertTrue(
            interface_exists(Castable::class),
            'The Castable interface must exist in Fw\Model namespace.',
        );
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionMethod(Model::class, $method);
        $file = file($ref->getFileName());
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
