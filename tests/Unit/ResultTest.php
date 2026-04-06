<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Support\Option;
use Fw\Support\Result;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResultTest extends TestCase
{
    // ==========================================
    // OK/ERR CREATION TESTS
    // ==========================================

    public function testOkCreatesSuccessResult(): void
    {
        $result = Result::ok('value');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isErr());
        $this->assertEquals('value', $result->unwrapOr(null));
    }

    public function testOkWithNullValue(): void
    {
        $result = Result::ok(null);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrapOr('not-null'));
    }

    public function testErrCreatesErrorResult(): void
    {
        $result = Result::err('error message');

        $this->assertTrue($result->isErr());
        $this->assertFalse($result->isOk());
        $this->assertEquals('error message', $result->unwrapErr());
    }

    // ==========================================
    // TRY TESTS
    // ==========================================

    public function testTryReturnsOkOnSuccess(): void
    {
        $result = Result::try(fn() => 'success');

        $this->assertTrue($result->isOk());
        $this->assertEquals('success', $result->unwrapOr(null));
    }

    public function testTryReturnsErrOnException(): void
    {
        $result = Result::try(fn() => throw new RuntimeException('failed'));

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(RuntimeException::class, $result->unwrapErr());
        $this->assertEquals('failed', $result->unwrapErr()->getMessage());
    }

    // ==========================================
    // FROM NULLABLE TESTS
    // ==========================================

    public function testFromNullableWithValue(): void
    {
        $result = Result::fromNullable('value', 'error');

        $this->assertTrue($result->isOk());
        $this->assertEquals('value', $result->unwrapOr(null));
    }

    public function testFromNullableWithNull(): void
    {
        $result = Result::fromNullable(null, 'not found');

        $this->assertTrue($result->isErr());
        $this->assertEquals('not found', $result->unwrapErr());
    }

    // ==========================================
    // UNWRAP TESTS
    // ==========================================

    public function testUnwrapOrReturnsValueOnOk(): void
    {
        $result = Result::ok('value');

        $this->assertEquals('value', $result->unwrapOr('default'));
    }

    public function testUnwrapOrReturnsDefaultOnErr(): void
    {
        $result = Result::err('error');

        $this->assertEquals('default', $result->unwrapOr('default'));
    }

    public function testUnwrapOrElseReturnsValueOnOk(): void
    {
        $result = Result::ok('value');

        $this->assertEquals('value', $result->unwrapOrElse(fn($e) => 'computed'));
    }

    public function testUnwrapOrElseComputesOnErr(): void
    {
        $result = Result::err('error');

        $this->assertEquals('handled: error', $result->unwrapOrElse(fn($e) => "handled: $e"));
    }

    public function testUnwrapErrThrowsOnOk(): void
    {
        $this->expectException(LogicException::class);

        Result::ok('value')->unwrapErr();
    }

    // ==========================================
    // MAP TESTS
    // ==========================================

    public function testMapTransformsValue(): void
    {
        $result = Result::ok(5)->map(fn($x) => $x * 2);

        $this->assertTrue($result->isOk());
        $this->assertEquals(10, $result->unwrapOr(null));
    }

    public function testMapPreservesError(): void
    {
        $result = Result::err('error')->map(fn($x) => $x * 2);

        $this->assertTrue($result->isErr());
        $this->assertEquals('error', $result->unwrapErr());
    }

    public function testMapErrTransformsError(): void
    {
        $result = Result::err('error')->mapErr(fn($e) => "mapped: $e");

        $this->assertTrue($result->isErr());
        $this->assertEquals('mapped: error', $result->unwrapErr());
    }

    public function testMapErrPreservesOk(): void
    {
        $result = Result::ok('value')->mapErr(fn($e) => "mapped: $e");

        $this->assertTrue($result->isOk());
        $this->assertEquals('value', $result->unwrapOr(null));
    }

    // ==========================================
    // FLATMAP TESTS
    // ==========================================

    public function testFlatMapChainsResults(): void
    {
        $divide = fn($a, $b) => $b === 0
            ? Result::err('division by zero')
            : Result::ok($a / $b);

        $result = Result::ok(10)->flatMap(fn($x) => $divide($x, 2));

        $this->assertTrue($result->isOk());
        $this->assertEquals(5, $result->unwrapOr(null));
    }

    public function testFlatMapPropagatesError(): void
    {
        $divide = fn($a, $b) => $b === 0
            ? Result::err('division by zero')
            : Result::ok($a / $b);

        $result = Result::ok(10)->flatMap(fn($x) => $divide($x, 0));

        $this->assertTrue($result->isErr());
        $this->assertEquals('division by zero', $result->unwrapErr());
    }

    public function testAndThenIsAliasForFlatMap(): void
    {
        $result = Result::ok(5)->andThen(fn($x) => Result::ok($x * 2));

        $this->assertEquals(10, $result->unwrapOr(null));
    }

    // ==========================================
    // OR ELSE TESTS
    // ==========================================

    public function testOrElseReturnsOriginalOnOk(): void
    {
        $result = Result::ok('first')->orElse(Result::ok('second'));

        $this->assertEquals('first', $result->unwrapOr(null));
    }

    public function testOrElseReturnsFallbackOnErr(): void
    {
        $result = Result::err('error')->orElse(Result::ok('fallback'));

        $this->assertEquals('fallback', $result->unwrapOr(null));
    }

    public function testOrElseTryComputesFallback(): void
    {
        $result = Result::err('error')->orElseTry(fn($e) => Result::ok("recovered from: $e"));

        $this->assertEquals('recovered from: error', $result->unwrapOr(null));
    }

    // ==========================================
    // TAP TESTS
    // ==========================================

    public function testTapExecutesOnOk(): void
    {
        $called = false;
        $result = Result::ok('value')->tap(function ($v) use (&$called) {
            $called = true;
            $this->assertEquals('value', $v);
        });

        $this->assertTrue($called);
        $this->assertSame('value', $result->unwrapOr(null));
    }

    public function testTapDoesNotExecuteOnErr(): void
    {
        $called = false;
        Result::err('error')->tap(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function testTapErrExecutesOnErr(): void
    {
        $called = false;
        Result::err('error')->tapErr(function ($e) use (&$called) {
            $called = true;
            $this->assertEquals('error', $e);
        });

        $this->assertTrue($called);
    }

    // ==========================================
    // CONTAINS TESTS
    // ==========================================

    public function testContainsReturnsTrueWhenPredicateMatches(): void
    {
        $result = Result::ok(10);

        $this->assertTrue($result->contains(fn($x) => $x > 5));
        $this->assertFalse($result->contains(fn($x) => $x > 15));
    }

    public function testContainsReturnsFalseOnErr(): void
    {
        $result = Result::err('error');

        $this->assertFalse($result->contains(fn($x) => true));
    }

    // ==========================================
    // MATCH TESTS
    // ==========================================

    public function testMatchCallsOkHandler(): void
    {
        $result = Result::ok('value')->match(
            ok: fn($v) => "success: $v",
            err: fn($e) => "error: $e"
        );

        $this->assertEquals('success: value', $result);
    }

    public function testMatchCallsErrHandler(): void
    {
        $result = Result::err('error')->match(
            ok: fn($v) => "success: $v",
            err: fn($e) => "error: $e"
        );

        $this->assertEquals('error: error', $result);
    }

    // ==========================================
    // TO OPTION TESTS
    // ==========================================

    public function testToOptionReturnsSomeOnOk(): void
    {
        $option = Result::ok('value')->toOption();

        $this->assertTrue($option->isSome());
        $this->assertEquals('value', $option->unwrapOr(null));
    }

    public function testToOptionReturnsNoneOnErr(): void
    {
        $option = Result::err('error')->toOption();

        $this->assertTrue($option->isNone());
    }

    // ==========================================
    // ALL TESTS
    // ==========================================

    public function testAllReturnsOkWithAllValues(): void
    {
        $results = [
            Result::ok(1),
            Result::ok(2),
            Result::ok(3),
        ];

        $combined = Result::all($results);

        $this->assertTrue($combined->isOk());
        $this->assertEquals([1, 2, 3], $combined->unwrapOr(null));
    }

    public function testAllReturnsFirstError(): void
    {
        $results = [
            Result::ok(1),
            Result::err('first error'),
            Result::err('second error'),
        ];

        $combined = Result::all($results);

        $this->assertTrue($combined->isErr());
        $this->assertEquals('first error', $combined->unwrapErr());
    }

    // ==========================================
    // ANY TESTS
    // ==========================================

    public function testAnyReturnsFirstSuccess(): void
    {
        $results = [
            Result::err('first'),
            Result::ok('success'),
            Result::err('third'),
        ];

        $combined = Result::any($results);

        $this->assertTrue($combined->isOk());
        $this->assertEquals('success', $combined->unwrapOr(null));
    }

    public function testAnyReturnsLastErrorIfAllFail(): void
    {
        $results = [
            Result::err('first'),
            Result::err('second'),
            Result::err('last'),
        ];

        $combined = Result::any($results);

        $this->assertTrue($combined->isErr());
        $this->assertEquals('last', $combined->unwrapErr());
    }
}
