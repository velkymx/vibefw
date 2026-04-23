<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Item #20: Router::compilePattern() validated custom route constraints
 * against parentheses, backreferences, and total length, but nothing
 * stopped classic ReDoS shapes — adjacent unbounded quantifiers on
 * overlapping character classes. `[a-z]+[a-z]+`, `.+.+`, or `a+b+`
 * against a non-matching input backtrack exponentially.
 *
 * Lock in: reject any constraint with more than one unbounded
 * quantifier (`+`, `*`, or `{n,}` / `{n,m}` with an open upper bound),
 * and keep bounded `{n,m}` with a finite upper bound. All existing
 * SAFE_CONSTRAINTS presets still compile (they each use exactly one
 * quantifier).
 */
final class RouterConstraintRedosTest extends TestCase
{
    /** Drive validateConstraint() directly. compilePattern() uses `[^}]+` to capture the constraint, which would truncate shapes containing `}` (like `{n,}`) before the validator ever sees them — so we call the validator straight through to exercise every shape. */
    private function registerConstraint(string $constraint): void
    {
        $router = new Router();
        $method = (new ReflectionClass(Router::class))->getMethod('validateConstraint');
        $method->invoke($router, $constraint, 'id');
    }

    #[Test]
    public function singleQuantifierAllowed(): void
    {
        $this->expectNotToPerformAssertions();
        $this->registerConstraint('[a-z]+');
        $this->registerConstraint('[0-9]*');
        $this->registerConstraint('[A-Z]{3}');
        $this->registerConstraint('[a-z0-9-]{1,50}');
    }

    #[Test]
    public function noQuantifierAllowed(): void
    {
        $this->expectNotToPerformAssertions();
        $this->registerConstraint('abc');
        $this->registerConstraint('[a-z]');
    }

    #[Test]
    public function adjacentUnboundedQuantifiersRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/redos|unbounded|quantif/i');
        $this->registerConstraint('[a-z]+[a-z]+');
    }

    #[Test]
    public function dotStarDotPlusRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registerConstraint('.+.+');
    }

    #[Test]
    public function multipleSingleCharQuantifiersRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registerConstraint('a+b+');
    }

    #[Test]
    public function plusThenStarRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registerConstraint('[a-z]+[0-9]*');
    }

    #[Test]
    public function openEndedBoundedQuantifierCountsAsUnbounded(): void
    {
        // `{n,}` with no upper bound is just as dangerous as `+`/`*`.
        $this->expectException(InvalidArgumentException::class);
        $this->registerConstraint('[a-z]{1,}[a-z]+');
    }

    #[Test]
    public function escapedQuantifierIsLiteral(): void
    {
        // Constraint matching a literal `+` followed by digits — one real quantifier, one escaped.
        $this->expectNotToPerformAssertions();
        $this->registerConstraint('\\+[0-9]+');
    }

    #[Test]
    public function twoBoundedQuantifiersAllowed(): void
    {
        // Bounded `{n,m}` with a finite upper bound cannot backtrack
        // exponentially — allow multiple.
        $this->expectNotToPerformAssertions();
        $this->registerConstraint('[a-z]{1,10}-[0-9]{1,4}');
    }
}
