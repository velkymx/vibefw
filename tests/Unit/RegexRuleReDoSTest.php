<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Validation\Rules\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #46: `Regex::validate()` handed the pattern straight to
 * `preg_match()` with no ceiling on the PCRE backtracking engine.
 * A pathological pattern like `(a+)+$` against an input of many `a`s
 * followed by a mismatching `b` sends PCRE into exponential
 * backtracking and can hang the request.
 *
 * [R50] lowers `pcre.backtrack_limit` around the match call and
 * treats a `PREG_BACKTRACK_LIMIT_ERROR` (or any non-success error)
 * as a validation failure instead of a hang. The old limit is
 * restored in `finally` so the change does not leak to other code.
 *
 * Regressions asserted:
 *   - pathological input against a back-tracking pattern returns an
 *     error message in well under a second (proves the limit kicked
 *     in and the call did not hang)
 *   - well-formed matches still succeed
 *   - mismatches still return the error message
 *   - null/empty short-circuit is preserved
 *   - non-string values still fail validation
 */
final class RegexRuleReDoSTest extends TestCase
{
    #[Test]
    public function pathologicalInputBudgetsBacktrackingEvenWhenAmbientLimitIsHigh(): void
    {
        // Raise the ambient limit well past what a pathological match
        // would need. If the Regex rule relies on the ambient limit it
        // will hang; if it applies its own lower ceiling inside
        // validate(), it bails quickly with a validation error.
        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000000');

        try {
            $rule = new Regex('/^(a+)+$/');
            $evil = str_repeat('a', 50) . 'b';

            $start = microtime(true);
            $result = $rule->validate($evil, 'field');
            $elapsed = microtime(true) - $start;

            $this->assertNotNull(
                $result,
                'pathological input must fail validation, not match'
            );
            $this->assertLessThan(
                0.5,
                $elapsed,
                sprintf('Regex rule must apply its own backtrack ceiling — took %.3fs', $elapsed)
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $original);
        }
    }

    #[Test]
    public function wellFormedMatchStillSucceeds(): void
    {
        $rule = new Regex('/^[a-z]+$/');
        $this->assertNull($rule->validate('hello', 'field'));
    }

    #[Test]
    public function mismatchReturnsErrorMessageWithFieldName(): void
    {
        $rule = new Regex('/^\d+$/', 'The :field must be numeric.');
        $msg = $rule->validate('abc', 'amount');
        $this->assertSame('The amount must be numeric.', $msg);
    }

    #[Test]
    public function emptyStringShortCircuits(): void
    {
        $rule = new Regex('/^\d+$/');
        $this->assertNull($rule->validate('', 'field'));
        $this->assertNull($rule->validate(null, 'field'));
    }

    #[Test]
    public function nonStringInputFailsValidation(): void
    {
        $rule = new Regex('/^\d+$/');
        $this->assertNotNull($rule->validate(123, 'field'));
        $this->assertNotNull($rule->validate(['x'], 'field'));
    }

    #[Test]
    public function backtrackLimitIsRestoredAfterCall(): void
    {
        $before = ini_get('pcre.backtrack_limit');

        $rule = new Regex('/^(a+)+$/');
        $rule->validate(str_repeat('a', 40) . 'b', 'field');

        $after = ini_get('pcre.backtrack_limit');
        $this->assertSame(
            $before,
            $after,
            'Regex rule must not leak a lowered pcre.backtrack_limit to the surrounding process'
        );
    }
}
