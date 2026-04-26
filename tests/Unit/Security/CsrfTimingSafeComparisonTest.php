<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Security;

use Fw\Security\Csrf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M17: CSRF token validation does not use timing-safe comparison.
 *
 * Pre-fix: The CSRF token comparison used `===` instead of `hash_equals()`.
 * While CSRF tokens are random and not user-guessable, timing attacks could
 * theoretically leak information about token validity.
 *
 * Post-fix: Verified that CSRF token validation uses `hash_equals()` for
 * timing-safe comparison, preventing theoretical timing attacks.
 */
final class CsrfTimingSafeComparisonTest extends TestCase
{
    #[Test]
    public function validateUsesHashEquals(): void
    {
        $source = file_get_contents((new \ReflectionClass(Csrf::class))->getFileName());
        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'CSRF validation should use hash_equals() for timing-safe comparison'
        );
    }

    #[Test]
    public function validateDoesNotUseStrictComparisonForTokens(): void
    {
        $source = file_get_contents((new \ReflectionClass(Csrf::class))->getFileName());

        // Find the validate method
        $lines = explode("\n", $source);
        $inValidateMethod = false;
        $foundTokenComparisonWithStrict = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Check if we're in the validate method
            if (str_contains($line, 'public function validate(')) {
                $inValidateMethod = true;
            }

            // Exit the validate method when we hit the next method
            if ($inValidateMethod && str_contains($line, 'public function') && !str_contains($line, 'validate(')) {
                break;
            }

            // Check for strict comparison with token variable (but exclude null check)
            if ($inValidateMethod && str_contains($line, '$token') && str_contains($line, '===') && !str_contains($line, '//') && !str_contains($line, 'null')) {
                $foundTokenComparisonWithStrict = true;
            }
        }

        $this->assertFalse($foundTokenComparisonWithStrict, 'CSRF validation should not use strict comparison (===) for tokens');
    }

    #[Test]
    public function validateUsesHashEqualsForSecretBasedTokens(): void
    {
        $source = file_get_contents((new \ReflectionClass(Csrf::class))->getFileName());
        $this->assertStringContainsString(
            'hash_equals($this->deriveToken',
            $source,
            'CSRF validation should use hash_equals() for secret-based tokens'
        );
    }

    #[Test]
    public function validateUsesHashEqualsForSessionBasedTokens(): void
    {
        $source = file_get_contents((new \ReflectionClass(Csrf::class))->getFileName());
        $this->assertStringContainsString(
            'hash_equals($_SESSION[self::SESSION_KEY]',
            $source,
            'CSRF validation should use hash_equals() for session-based tokens'
        );
    }

    #[Test]
    public function validateChecksForNullToken(): void
    {
        $source = file_get_contents((new \ReflectionClass(Csrf::class))->getFileName());
        $this->assertStringContainsString(
            'if ($token === null)',
            $source,
            'CSRF validation should check for null token'
        );
    }
}
