<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Validation\Rules;

use Fw\Validation\Rules\Required;
use Fw\Validation\Rules\MinLength;
use Fw\Validation\Rules\MaxLength;
use Fw\Validation\Rules\Between;
use Fw\Validation\Rules\Email;
use Fw\Validation\Rules\Numeric;
use Fw\Validation\Rules\Integer;
use Fw\Validation\Rules\Min;
use Fw\Validation\Rules\Max;
use Fw\Validation\Rules\In;
use Fw\Validation\Rules\Alpha;
use Fw\Validation\Rules\AlphaNumeric;
use Fw\Validation\Rules\Url;
use Fw\Validation\Rules\Uuid;
use Fw\Validation\Rules\Date;
use Fw\Validation\Rules\Confirmed;
use Fw\Validation\Rules\Nullable;
use Fw\Validation\Rules\Regex;
use Fw\Validation\Rules\InEnum;
use Fw\Validation\Rules\Exists;
use Fw\Validation\Rules\Unique;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L3: Rule classes implement __toString() for debug output.
 *
 * Pre-fix: Rule classes do not implement __toString(), so debugging output
 * shows class names like Fw\Validation\Rules\Required instead of
 * human-readable descriptions.
 *
 * Post-fix: All Rule classes implement __toString() to return a
 * human-readable description (e.g. "required", "min:3").
 */
final class RuleToStringTest extends TestCase
{
    #[Test]
    public function requiredRuleToString(): void
    {
        $rule = new Required();
        $this->assertSame('required', (string) $rule);
    }

    #[Test]
    public function minLengthRuleToString(): void
    {
        $rule = new MinLength(3);
        $this->assertSame('min:3', (string) $rule);
    }

    #[Test]
    public function maxLengthRuleToString(): void
    {
        $rule = new MaxLength(255);
        $this->assertSame('max:255', (string) $rule);
    }

    #[Test]
    public function betweenRuleToString(): void
    {
        $rule = new Between(1, 100);
        $this->assertSame('between:1,100', (string) $rule);
    }

    #[Test]
    public function emailRuleToString(): void
    {
        $rule = new Email();
        $this->assertSame('email', (string) $rule);
    }

    #[Test]
    public function numericRuleToString(): void
    {
        $rule = new Numeric();
        $this->assertSame('numeric', (string) $rule);
    }

    #[Test]
    public function integerRuleToString(): void
    {
        $rule = new Integer();
        $this->assertSame('integer', (string) $rule);
    }

    #[Test]
    public function minRuleToString(): void
    {
        $rule = new Min(0);
        $this->assertSame('min:0', (string) $rule);
    }

    #[Test]
    public function maxRuleToString(): void
    {
        $rule = new Max(100);
        $this->assertSame('max:100', (string) $rule);
    }

    #[Test]
    public function inRuleToString(): void
    {
        $rule = new In(['active', 'inactive']);
        $this->assertSame('in:active,inactive', (string) $rule);
    }

    #[Test]
    public function alphaRuleToString(): void
    {
        $rule = new Alpha();
        $this->assertSame('alpha', (string) $rule);
    }

    #[Test]
    public function alphaNumericRuleToString(): void
    {
        $rule = new AlphaNumeric();
        $this->assertSame('alphanumeric', (string) $rule);
    }

    #[Test]
    public function urlRuleToString(): void
    {
        $rule = new Url();
        $this->assertSame('url', (string) $rule);
    }

    #[Test]
    public function uuidRuleToString(): void
    {
        $rule = new Uuid();
        $this->assertSame('uuid', (string) $rule);
    }

    #[Test]
    public function dateRuleToString(): void
    {
        $rule = new Date();
        $this->assertSame('date', (string) $rule);
    }

    #[Test]
    public function confirmedRuleToString(): void
    {
        $rule = new Confirmed();
        $this->assertSame('confirmed', (string) $rule);
    }

    #[Test]
    public function nullableRuleToString(): void
    {
        $rule = new Nullable();
        $this->assertSame('nullable', (string) $rule);
    }

    #[Test]
    public function regexRuleToString(): void
    {
        $rule = new Regex('/^[a-z]+$/');
        $this->assertSame('regex:/^[a-z]+$/', (string) $rule);
    }

    #[Test]
    public function inEnumRuleToString(): void
    {
        $rule = new InEnum(TestStatus::class);
        $this->assertSame('enum:' . TestStatus::class, (string) $rule);
    }

    #[Test]
    public function existsRuleToString(): void
    {
        $rule = new Exists('users', 'id');
        $this->assertSame('exists:users,id', (string) $rule);
    }

    #[Test]
    public function uniqueRuleToString(): void
    {
        $rule = new Unique('users', 'email');
        $this->assertSame('unique:users,email', (string) $rule);
    }
}
