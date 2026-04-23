<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Config\ConfigValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #38: the TODO warned that `validate()` compared `$value <
 * $rules['min']` with loose typing and claimed a string like "10
 * apples" would coerce to 10 and bypass validation. That premise is
 * inaccurate — `is_numeric("10 apples")` returns false on PHP 8.x,
 * so the existing `is_numeric($value) && $value < $rules['min']`
 * guard already skips min/max for any non-numeric string.
 *
 * [R43] still adopts the TODO's proposed explicit `(float)$value`
 * cast for two reasons:
 *   1. Intent — the comparison no longer relies on PHP's implicit
 *      numeric-string coercion rules, which have subtly drifted
 *      across versions and now warn in some contexts.
 *   2. This test locks the behaviour across the edge cases the TODO
 *      and real callers actually care about, so a future refactor
 *      can't regress either direction.
 */
final class ConfigValidatorMinMaxTest extends TestCase
{
    private string $schemaName;

    protected function setUp(): void
    {
        // Unique schema name per test so registrations don't leak via
        // ConfigValidator's static $schemas map.
        $this->schemaName = 'test_' . bin2hex(random_bytes(4));
    }

    private function register(array $rules): void
    {
        ConfigValidator::registerSchema($this->schemaName, ['n' => $rules]);
    }

    private function validate(mixed $value): array
    {
        $result = ConfigValidator::validate($this->schemaName, ['n' => $value]);
        return $result->isErr() ? $result->unwrapErr() : [];
    }

    #[Test]
    public function integerBelowMinProducesError(): void
    {
        $this->register(['min' => 10]);
        $errors = $this->validate(5);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 10', $errors[0]);
    }

    #[Test]
    public function integerAtMinPasses(): void
    {
        $this->register(['min' => 10]);
        $this->assertSame([], $this->validate(10));
    }

    #[Test]
    public function integerAboveMaxProducesError(): void
    {
        $this->register(['max' => 100]);
        $errors = $this->validate(150);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at most 100', $errors[0]);
    }

    #[Test]
    public function numericStringBelowMinProducesError(): void
    {
        $this->register(['min' => 10]);
        $errors = $this->validate('5');
        $this->assertNotEmpty($errors, 'numeric string "5" must be compared as 5 and fail min=10');
    }

    #[Test]
    public function numericStringWithinRangePasses(): void
    {
        $this->register(['min' => 1, 'max' => 100]);
        $this->assertSame([], $this->validate('50'));
    }

    #[Test]
    public function nonNumericStringWith10PrefixDoesNotTriggerMinMaxCoercion(): void
    {
        // This is the TODO's scare example. `is_numeric("10 apples")`
        // is false, so the guard skips min/max — the value never
        // silently passes as 10.
        $this->register(['min' => 100]);
        $errors = $this->validate('10 apples');
        $this->assertSame(
            [],
            $errors,
            'non-numeric string must skip min/max entirely, not coerce to 10 and spuriously fail'
        );
    }

    #[Test]
    public function scientificNotationStringIsComparedNumerically(): void
    {
        $this->register(['min' => 1, 'max' => 100]);
        // "5e1" == 50 — inside range
        $this->assertSame([], $this->validate('5e1'));
        // "5e3" == 5000 — above max
        $errors = $this->validate('5e3');
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function floatComparisonRespectsFractionalBoundary(): void
    {
        $this->register(['min' => 1.5, 'max' => 2.5]);
        $this->assertSame([], $this->validate(2.0));
        $this->assertNotEmpty($this->validate(1.4));
        $this->assertNotEmpty($this->validate(2.6));
    }

    #[Test]
    public function zeroIsRespectedAsValidMin(): void
    {
        $this->register(['min' => 0]);
        $this->assertSame([], $this->validate(0));
        $this->assertNotEmpty($this->validate(-1));
    }

    #[Test]
    public function portSchemaRejectsOutOfRangeIntegers(): void
    {
        // Realistic shape from registerDefaultSchemas().
        $this->register(['type' => 'int', 'min' => 1, 'max' => 65535]);
        $this->assertNotEmpty($this->validate(0));
        $this->assertNotEmpty($this->validate(70000));
        $this->assertSame([], $this->validate(8080));
    }
}
