<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Domain\Id;
use Fw\Repository\InMemoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #34: the old `matchesLike()` ran
 *
 *   $regex = str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/'));
 *   $regex = str_replace(['\\%', '\\_', '\\.*', '\\.'], …, $regex);
 *
 * which was unreachable by construction — `preg_quote` never escapes
 * `%` or `_` (they aren't regex metachars), so the second `str_replace`
 * pass only fires on patterns that literally contained a backslash,
 * where it mangled every `\.` and `\.*` the author actually wanted to
 * keep. The result: `\%` and `\_` literal-escapes didn't work, and
 * any pattern with a `\\` or a literal `.` was unpredictable.
 *
 * After [R39] `matchesLike()` walks the pattern byte-by-byte and
 * handles the three SQL LIKE escape sequences directly:
 *   - `\%` → literal `%`
 *   - `\_` → literal `_`
 *   - `\\` → literal `\`
 * Everything else goes through `preg_quote` unchanged, so dots,
 * brackets, parens, pipes, and other regex metachars are literal.
 */
final class InMemoryRepositoryMatchesLikeTest extends TestCase
{
    /**
     * Tiny concrete subclass so the protected `matchesLike()` can be
     * invoked. No data setup needed for this method.
     */
    private function repo(): InMemoryRepository
    {
        return new class () extends InMemoryRepository {
            public function expose(mixed $value, string $pattern): bool
            {
                return $this->matchesLike($value, $pattern);
            }

            protected function getId(mixed $entity): Id
            {
                throw new \LogicException('not used in this test');
            }

            protected function getEntityClass(): string
            {
                return 'stdClass';
            }
        };
    }

    /** @return array<string, array{string, string, bool}> */
    public static function patternProvider(): array
    {
        return [
            // Basic wildcards
            'percent matches prefix'         => ['abc',  'a%',   true],
            'percent matches middle'         => ['abc',  'a%c',  true],
            'percent matches zero chars'     => ['a',    'a%',   true],
            'percent matches nothing'        => ['',     '%',    true],
            'exact literal match'            => ['abc',  'abc',  true],
            'literal mismatch'               => ['abd',  'abc',  false],
            'underscore matches one'         => ['abc',  'a_c',  true],
            'underscore rejects zero'        => ['ac',   'a_c',  false],
            'underscore rejects two'         => ['abbc', 'a_c',  false],

            // Escape sequences (the part that was broken)
            'escaped percent is literal'     => ['a%b',  'a\\%b', true],
            'escaped percent rejects wild'   => ['axb',  'a\\%b', false],
            'escaped underscore is literal'  => ['a_b',  'a\\_b', true],
            'escaped underscore rejects one' => ['axb',  'a\\_b', false],
            'escaped backslash is literal'   => ['a\\b', 'a\\\\b', true],

            // Regex metacharacters must be literal in LIKE
            'literal dot is not wild'        => ['a.b',  'a.b',  true],
            'literal dot rejects other'      => ['axb',  'a.b',  false],
            'literal pipe is not alternation' => ['a|b', 'a|b', true],
            'literal paren'                  => ['a(b)c', 'a(b)c', true],
            'literal bracket'                => ['a[b]', 'a[b]',   true],
            'plus is literal'                => ['a+b',  'a+b',   true],
            'star is literal'                => ['a*b',  'a*b',   true],

            // Case insensitivity preserved
            'case insensitive upper'         => ['abc', 'A%',   true],
            'case insensitive lower'         => ['ABC', 'a%',   true],

            // Non-string values short-circuit to false
            'non-string int'                 => [42,     '%',   false],
            'non-string null'                => [null,   '%',   false],
            'non-string array'               => [['x'],  '%',   false],
        ];
    }

    #[Test]
    #[DataProvider('patternProvider')]
    public function matchesLikeBehavesPerSqlSemantics(mixed $value, string $pattern, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->repo()->expose($value, $pattern),
            "value=" . var_export($value, true) . " pattern={$pattern}"
        );
    }
}
