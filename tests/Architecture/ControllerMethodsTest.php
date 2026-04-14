<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verify that removed v2 Controller methods no longer exist,
 * and that kept methods still exist.
 */
final class ControllerMethodsTest extends TestCase
{
    /**
     * Methods that must NOT exist on Controller (v3 breaking removal).
     *
     * @return array<string, array{string}>
     */
    public static function removedMethodsProvider(): array
    {
        return [
            'dbQuery'      => ['dbQuery'],
            'dbQueryOne'   => ['dbQueryOne'],
            'validate'     => ['validate'],
            'checkRule'    => ['checkRule'],
            'abort'        => ['abort'],
            'input'        => ['input'],
            'only'         => ['only'],
            'except'       => ['except'],
            'has'          => ['has'],
        ];
    }

    /**
     * Methods that MUST still exist on Controller.
     *
     * @return array<string, array{string}>
     */
    public static function keptMethodsProvider(): array
    {
        return [
            'view'            => ['view'],
            'cachedView'      => ['cachedView'],
            'streamedView'    => ['streamedView'],
            'json'            => ['json'],
            'redirect'        => ['redirect'],
            'back'            => ['back'],
            'notFound'        => ['notFound'],
            'forbidden'       => ['forbidden'],
            'badRequest'      => ['badRequest'],
            'serverError'     => ['serverError'],
            'noContent'       => ['noContent'],
            'dispatch'        => ['dispatch'],
            'query'           => ['query'],
            'emit'            => ['emit'],
            'user'            => ['user'],
            'isAuthenticated' => ['isAuthenticated'],
        ];
    }

    #[Test]
    #[DataProvider('removedMethodsProvider')]
    public function controllerDoesNotHaveRemovedMethod(string $method): void
    {
        $ref = new ReflectionClass(\Fw\Core\Controller::class);

        $this->assertFalse(
            $ref->hasMethod($method),
            "Controller must not have removed method: {$method}()"
        );
    }

    #[Test]
    #[DataProvider('keptMethodsProvider')]
    public function controllerStillHasKeptMethod(string $method): void
    {
        $ref = new ReflectionClass(\Fw\Core\Controller::class);

        $this->assertTrue(
            $ref->hasMethod($method),
            "Controller must still have method: {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function busMethodProvider(): array
    {
        return [
            'dispatch' => ['dispatch'],
            'query'    => ['query'],
        ];
    }

    #[Test]
    #[DataProvider('busMethodProvider')]
    public function busMethodDocblockUsesFullyQualifiedThrowable(string $method): void
    {
        $ref = new ReflectionClass(\Fw\Core\Controller::class);
        $doc = $ref->getMethod($method)->getDocComment();

        $this->assertNotFalse($doc, "Method {$method}() must have a docblock");

        // @return Result<T, Throwable> must use \Throwable (FQCN) so PHPStan
        // resolves the global interface, not Fw\Core\Throwable.
        $this->assertStringContainsString(
            '\Throwable',
            $doc,
            "Method {$method}() @return must reference \Throwable (FQCN), not bare Throwable"
        );
    }
}
