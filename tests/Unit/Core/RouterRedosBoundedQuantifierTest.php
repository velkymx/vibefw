<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * M7: Router ReDoS via unbounded `.` quantifier in `any` constraint.
 *
 * The `any` safe constraint used `.+` which matches any character
 * including slashes. Two adjacent `:any` parameters would generate
 * overlapping `.+` `.+` quantifiers — a classic ReDoS pattern.
 * After fix, `any` uses `[^/]+` which can't overlap across segments.
 */
final class RouterRedosBoundedQuantifierTest extends TestCase
{
    #[Test]
    public function anyConstraintDoesNotUseDotPlus(): void
    {
        $ref = new ReflectionClass(Router::class);
        $constraints = $ref->getConstant('SAFE_CONSTRAINTS');
        $this->assertIsArray($constraints);
        $this->assertArrayHasKey('any', $constraints);
        $this->assertStringNotContainsString('.+', $constraints['any'], 'The "any" constraint should not use .+ (ReDoS risk with overlapping quantifiers)');
    }

    #[Test]
    public function anyConstraintUsesNonSlashClass(): void
    {
        $ref = new ReflectionClass(Router::class);
        $constraints = $ref->getConstant('SAFE_CONSTRAINTS');
        $this->assertSame('[^/]+', $constraints['any'], '"any" constraint should use [^/]+ to avoid ReDoS');
    }

    #[Test]
    public function allSafeConstraintsHaveBoundedOrNonOverlappingQuantifiers(): void
    {
        $ref = new ReflectionClass(Router::class);
        $constraints = $ref->getConstant('SAFE_CONSTRAINTS');

        foreach ($constraints as $name => $pattern) {
            if ($name === 'uuid') {
                continue;
            }
            $unescaped = preg_replace('/\\\\./', '', $pattern) ?? '';
            $unboundedCount = preg_match_all('/[+*]|\{\d+,\}/', $unescaped);
            $this->assertLessThanOrEqual(1, $unboundedCount, "Safe constraint '{$name}' has {$unboundedCount} unbounded quantifiers — at most 1 allowed");
        }
    }

    #[Test]
    public function noSafeConstraintUsesDotQuantifier(): void
    {
        $ref = new ReflectionClass(Router::class);
        $constraints = $ref->getConstant('SAFE_CONSTRAINTS');

        foreach ($constraints as $name => $pattern) {
            $this->assertStringNotContainsString('.+', $pattern, "Safe constraint '{$name}' should not use .+ quantifier");
            $this->assertStringNotContainsString('.*', $pattern, "Safe constraint '{$name}' should not use .* quantifier");
        }
    }

    #[Test]
    public function customConstraintWithMultipleUnboundedQuantifiersIsRejected(): void
    {
        $router = new Router();
        $m = (new ReflectionClass(Router::class))->getMethod('validateConstraint');

        $this->expectException(\InvalidArgumentException::class);
        $m->invoke($router, '[a-z]+[a-z]+', 'test');
    }
}
