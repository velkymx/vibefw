<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\ForbiddenException;
use Fw\Auth\Gate;
use Fw\Tests\TestCase;

/**
 * Gate tests.
 *
 * Note: Full Gate tests require App\Models\User and App\Policies classes.
 * These tests cover the exception behavior and static method availability.
 */
final class GateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::flushCache();
    }

    protected function tearDown(): void
    {
        Gate::flushCache();
        parent::tearDown();
    }

    public function testForbiddenExceptionHasCorrectCode(): void
    {
        $exception = new ForbiddenException();

        $this->assertEquals(403, $exception->getCode());
    }

    public function testForbiddenExceptionHasDefaultMessage(): void
    {
        $exception = new ForbiddenException();

        $this->assertStringContainsString('This action is unauthorized.', $exception->getMessage());
        $this->assertStringContainsString('php fw routes:list --middleware', $exception->getMessage());
    }

    public function testForbiddenExceptionAcceptsCustomMessage(): void
    {
        $exception = new ForbiddenException('Custom message');

        $this->assertEquals('Custom message', $exception->getMessage());
    }

    public function testFlushCacheMethodExists(): void
    {
        // Just verify the method exists and can be called
        Gate::flushCache();
        $this->assertTrue(true);
    }

    public function testAllowsMethodExists(): void
    {
        $this->assertTrue(method_exists(Gate::class, 'allows'));
    }

    public function testDeniesMethodExists(): void
    {
        $this->assertTrue(method_exists(Gate::class, 'denies'));
    }

    public function testAuthorizeMethodExists(): void
    {
        $this->assertTrue(method_exists(Gate::class, 'authorize'));
    }

    public function testCheckMethodExists(): void
    {
        $this->assertTrue(method_exists(Gate::class, 'check'));
    }
}
