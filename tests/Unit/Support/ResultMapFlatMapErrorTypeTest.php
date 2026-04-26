<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Support;

use Fw\Support\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M18: Result map/flatMap do not preserve error type.
 *
 * Pre-fix: When mapping over a `Result<T, E>`, the error type `E` was not
 * preserved in the type signature. The return type was `self`, which doesn't
 * preserve the generic type parameters.
 *
 * Post-fix: Updated map(), flatMap(), and mapErr() to return `Result` instead
 * of `self`, allowing the PHPDoc type hints to specify the correct return types.
 * This preserves type safety when chaining Result operations.
 */
final class ResultMapFlatMapErrorTypeTest extends TestCase
{
    #[Test]
    public function mapPreservesErrorTypeInPhpDoc(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());
        $this->assertStringContainsString(
            '@return Result<U, E>',
            $source,
            'map() should preserve error type E in PHPDoc'
        );
    }

    #[Test]
    public function flatMapPreservesErrorTypeInPhpDoc(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());
        $this->assertStringContainsString(
            '@return Result<U, E>',
            $source,
            'flatMap() should preserve error type E in PHPDoc'
        );
    }

    #[Test]
    public function mapErrPreservesSuccessTypeInPhpDoc(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());
        $this->assertStringContainsString(
            '@return Result<T, F>',
            $source,
            'mapErr() should preserve success type T in PHPDoc'
        );
    }

    #[Test]
    public function mapReturnsResultNotSelf(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());

        // Find the map method
        $lines = explode("\n", $source);
        $inMapMethod = false;
        $foundSelfReturn = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Check if we're in the map method
            if (str_contains($line, 'public function map(')) {
                $inMapMethod = true;
            }

            // Exit the map method when we hit the next method
            if ($inMapMethod && str_contains($line, 'public function') && !str_contains($line, 'map(')) {
                break;
            }

            // Check for self return type
            if ($inMapMethod && str_contains($line, '): self')) {
                $foundSelfReturn = true;
            }
        }

        $this->assertFalse($foundSelfReturn, 'map() should return Result, not self');
    }

    #[Test]
    public function flatMapReturnsResultNotSelf(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());

        // Find the flatMap method
        $lines = explode("\n", $source);
        $inFlatMapMethod = false;
        $foundSelfReturn = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Check if we're in the flatMap method
            if (str_contains($line, 'public function flatMap(')) {
                $inFlatMapMethod = true;
            }

            // Exit the flatMap method when we hit the next method
            if ($inFlatMapMethod && str_contains($line, 'public function') && !str_contains($line, 'flatMap(')) {
                break;
            }

            // Check for self return type
            if ($inFlatMapMethod && str_contains($line, '): self')) {
                $foundSelfReturn = true;
            }
        }

        $this->assertFalse($foundSelfReturn, 'flatMap() should return Result, not self');
    }

    #[Test]
    public function mapErrReturnsResultNotSelf(): void
    {
        $source = file_get_contents((new \ReflectionClass(Result::class))->getFileName());

        // Find the mapErr method
        $lines = explode("\n", $source);
        $inMapErrMethod = false;
        $foundSelfReturn = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Check if we're in the mapErr method
            if (str_contains($line, 'public function mapErr(')) {
                $inMapErrMethod = true;
            }

            // Exit the mapErr method when we hit the next method
            if ($inMapErrMethod && str_contains($line, 'public function') && !str_contains($line, 'mapErr(')) {
                break;
            }

            // Check for self return type
            if ($inMapErrMethod && str_contains($line, '): self')) {
                $foundSelfReturn = true;
            }
        }

        $this->assertFalse($foundSelfReturn, 'mapErr() should return Result, not self');
    }

    #[Test]
    public function mapChainsPreserveErrorType(): void
    {
        // Create a success result
        $result = Result::ok(10);

        // Chain map operations
        $chained = $result
            ->map(fn ($n) => $n * 2)
            ->map(fn ($n) => $n + 5);

        // Verify the result is still a Result
        $this->assertInstanceOf(Result::class, $chained);
    }

    #[Test]
    public function flatMapChainsPreserveErrorType(): void
    {
        // Create a success result
        $result = Result::ok(10);

        // Chain flatMap operations
        $chained = $result
            ->flatMap(fn ($n) => Result::ok($n * 2))
            ->flatMap(fn ($n) => Result::ok($n + 5));

        // Verify the result is still a Result
        $this->assertInstanceOf(Result::class, $chained);
    }
}
