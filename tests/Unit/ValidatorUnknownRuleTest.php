<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Security\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item C7 — applyRule() previously resolved a rule via
 * `method_exists($this, 'validate' . ucfirst($name))` and silently
 * `return`'d when the method was missing. A typo like `requied`
 * passed validation without ever being checked: the field landed
 * in `validated()` and the developer thought they had enforced
 * `required`.
 *
 * Post-fix: an unknown rule name throws InvalidArgumentException
 * so silent misses become loud, immediate failures.
 */
final class ValidatorUnknownRuleTest extends TestCase
{
    #[Test]
    public function unknownRuleNameThrows(): void
    {
        $validator = Validator::make(['name' => 'John'], ['name' => 'requied']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown.*rule|rule.*unknown|requied/i');

        $validator->validate();
    }

    #[Test]
    public function unknownRuleInPipeListThrows(): void
    {
        $validator = Validator::make(['email' => 'a@b.c'], ['email' => 'required|emial|max:255']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/emial/i');

        $validator->validate();
    }

    #[Test]
    public function unknownRuleInArrayListThrows(): void
    {
        $validator = Validator::make(
            ['count' => '5'],
            ['count' => ['required', 'integre']],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/integre/i');

        $validator->validate();
    }

    #[Test]
    public function emptyRuleStringThrows(): void
    {
        // `required||email` (accidental double pipe) used to skip
        // the empty token silently — now it must trip the same
        // unknown-rule guard so the typo gets caught at the first
        // call site instead of leaking through.
        $validator = Validator::make(['email' => 'a@b.c'], ['email' => 'required||email']);

        $this->expectException(InvalidArgumentException::class);

        $validator->validate();
    }

    #[Test]
    public function knownRulesStillRunAfterFix(): void
    {
        // Sanity: the unknown-rule throw must not regress the happy
        // path. Existing string-pipe rule names continue to work.
        $passed = Validator::make(['n' => 'John'], ['n' => 'required'])->passes();
        $this->assertTrue($passed);

        $failed = Validator::make(['n' => ''], ['n' => 'required|min:3'])->fails();
        $this->assertTrue($failed);
    }
}
