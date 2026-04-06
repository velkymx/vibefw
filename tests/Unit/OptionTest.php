<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Support\Option;
use Fw\Support\Result;
use PHPUnit\Framework\TestCase;

final class OptionTest extends TestCase
{
    // ==========================================
    // SOME/NONE CREATION TESTS
    // ==========================================

    public function testSomeCreatesValueOption(): void
    {
        $option = Option::some('value');

        $this->assertTrue($option->isSome());
        $this->assertFalse($option->isNone());
        $this->assertEquals('value', $option->unwrapOr(null));
    }

    public function testSomeWithNullValue(): void
    {
        $option = Option::some(null);

        $this->assertTrue($option->isSome());
        $this->assertNull($option->unwrapOr(null));
    }

    public function testNoneCreatesEmptyOption(): void
    {
        $option = Option::none();

        $this->assertTrue($option->isNone());
        $this->assertFalse($option->isSome());
    }

    // ==========================================
    // FROM NULLABLE TESTS
    // ==========================================

    public function testFromNullableWithValue(): void
    {
        $option = Option::fromNullable('value');

        $this->assertTrue($option->isSome());
        $this->assertEquals('value', $option->unwrapOr(null));
    }

    public function testFromNullableWithNull(): void
    {
        $option = Option::fromNullable(null);

        $this->assertTrue($option->isNone());
    }

    public function testFromNullableWithZero(): void
    {
        $option = Option::fromNullable(0);

        $this->assertTrue($option->isSome());
        $this->assertEquals(0, $option->unwrapOr(null));
    }

    public function testFromNullableWithEmptyString(): void
    {
        $option = Option::fromNullable('');

        $this->assertTrue($option->isSome());
        $this->assertEquals('', $option->unwrapOr(null));
    }

    // ==========================================
    // UNWRAP TESTS
    // ==========================================

    public function testUnwrapOrReturnsValueOnSome(): void
    {
        $option = Option::some('value');

        $this->assertEquals('value', $option->unwrapOr('default'));
    }

    public function testUnwrapOrReturnsDefaultOnNone(): void
    {
        $option = Option::none();

        $this->assertEquals('default', $option->unwrapOr('default'));
    }

    public function testUnwrapOrElseReturnsValueOnSome(): void
    {
        $option = Option::some('value');

        $this->assertEquals('value', $option->unwrapOrElse(fn() => 'computed'));
    }

    public function testUnwrapOrElseComputesOnNone(): void
    {
        $option = Option::none();

        $this->assertEquals('computed', $option->unwrapOrElse(fn() => 'computed'));
    }

    public function testGetReturnsValueOrNull(): void
    {
        $this->assertEquals('value', Option::some('value')->get());
        $this->assertNull(Option::none()->get());
    }

    // ==========================================
    // MAP TESTS
    // ==========================================

    public function testMapTransformsValue(): void
    {
        $option = Option::some(5)->map(fn($x) => $x * 2);

        $this->assertTrue($option->isSome());
        $this->assertEquals(10, $option->unwrapOr(null));
    }

    public function testMapPreservesNone(): void
    {
        $option = Option::none()->map(fn($x) => $x * 2);

        $this->assertTrue($option->isNone());
    }

    // ==========================================
    // FLATMAP TESTS
    // ==========================================

    public function testFlatMapChainsOptions(): void
    {
        $parseInt = fn($s) => is_numeric($s) ? Option::some((int) $s) : Option::none();

        $option = Option::some('42')->flatMap($parseInt);

        $this->assertTrue($option->isSome());
        $this->assertEquals(42, $option->unwrapOr(null));
    }

    public function testFlatMapPropagatesNone(): void
    {
        $parseInt = fn($s) => is_numeric($s) ? Option::some((int) $s) : Option::none();

        $option = Option::some('not a number')->flatMap($parseInt);

        $this->assertTrue($option->isNone());
    }

    public function testFlatMapOnNone(): void
    {
        $option = Option::none()->flatMap(fn($x) => Option::some($x * 2));

        $this->assertTrue($option->isNone());
    }

    public function testAndThenIsAliasForFlatMap(): void
    {
        $option = Option::some(5)->andThen(fn($x) => Option::some($x * 2));

        $this->assertEquals(10, $option->unwrapOr(null));
    }

    // ==========================================
    // OR ELSE TESTS
    // ==========================================

    public function testOrElseReturnsOriginalOnSome(): void
    {
        $option = Option::some('first')->orElse(Option::some('second'));

        $this->assertEquals('first', $option->unwrapOr(null));
    }

    public function testOrElseReturnsFallbackOnNone(): void
    {
        $option = Option::none()->orElse(Option::some('fallback'));

        $this->assertEquals('fallback', $option->unwrapOr(null));
    }

    public function testOrElseTryComputesFallback(): void
    {
        $option = Option::none()->orElseTry(fn() => Option::some('computed'));

        $this->assertEquals('computed', $option->unwrapOr(null));
    }

    // ==========================================
    // FILTER TESTS
    // ==========================================

    public function testFilterKeepsMatchingValue(): void
    {
        $option = Option::some(10)->filter(fn($x) => $x > 5);

        $this->assertTrue($option->isSome());
        $this->assertEquals(10, $option->unwrapOr(null));
    }

    public function testFilterRemovesNonMatchingValue(): void
    {
        $option = Option::some(3)->filter(fn($x) => $x > 5);

        $this->assertTrue($option->isNone());
    }

    public function testFilterPreservesNone(): void
    {
        $option = Option::none()->filter(fn($x) => true);

        $this->assertTrue($option->isNone());
    }

    // ==========================================
    // TAP TESTS
    // ==========================================

    public function testTapExecutesOnSome(): void
    {
        $called = false;
        $option = Option::some('value')->tap(function ($v) use (&$called) {
            $called = true;
            $this->assertEquals('value', $v);
        });

        $this->assertTrue($called);
        $this->assertEquals('value', $option->unwrapOr(null));
    }

    public function testTapDoesNotExecuteOnNone(): void
    {
        $called = false;
        Option::none()->tap(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    // ==========================================
    // CONTAINS TESTS
    // ==========================================

    public function testContainsReturnsTrueWhenPredicateMatches(): void
    {
        $option = Option::some(10);

        $this->assertTrue($option->contains(fn($x) => $x > 5));
        $this->assertFalse($option->contains(fn($x) => $x > 15));
    }

    public function testContainsReturnsFalseOnNone(): void
    {
        $option = Option::none();

        $this->assertFalse($option->contains(fn($x) => true));
    }

    // ==========================================
    // MATCH TESTS
    // ==========================================

    public function testMatchCallsSomeHandler(): void
    {
        $result = Option::some('value')->match(
            fn($v) => "has: $v",
            fn() => 'empty'
        );

        $this->assertEquals('has: value', $result);
    }

    public function testMatchCallsNoneHandler(): void
    {
        $result = Option::none()->match(
            fn($v) => "has: $v",
            fn() => 'empty'
        );

        $this->assertEquals('empty', $result);
    }

    // ==========================================
    // TO RESULT TESTS
    // ==========================================

    public function testToResultReturnsOkOnSome(): void
    {
        $result = Option::some('value')->toResult('error');

        $this->assertTrue($result->isOk());
        $this->assertEquals('value', $result->unwrapOr(null));
    }

    public function testToResultReturnsErrOnNone(): void
    {
        $result = Option::none()->toResult('not found');

        $this->assertTrue($result->isErr());
        $this->assertEquals('not found', $result->unwrapErr());
    }

    // ==========================================
    // ZIP TESTS
    // ==========================================

    public function testZipCombinesTwoSomes(): void
    {
        $option = Option::some('a')->zip(Option::some('b'));

        $this->assertTrue($option->isSome());
        $this->assertEquals(['a', 'b'], $option->unwrapOr(null));
    }

    public function testZipReturnsNoneIfFirstIsNone(): void
    {
        $option = Option::none()->zip(Option::some('b'));

        $this->assertTrue($option->isNone());
    }

    public function testZipReturnsNoneIfSecondIsNone(): void
    {
        $option = Option::some('a')->zip(Option::none());

        $this->assertTrue($option->isNone());
    }
}
