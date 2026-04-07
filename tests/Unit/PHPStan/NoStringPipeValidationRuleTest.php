<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan;

use Fw\PHPStan\Rules\NoStringPipeValidationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoStringPipeValidationRule>
 */
final class NoStringPipeValidationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoStringPipeValidationRule();
    }

    public function testDetectsStringPipeRules(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/StringPipeValidation.php'], [
            [
                'String pipe validation rules are not allowed in v3. Use typed Rule objects: [new Required, new MinLength(3)]. Run: php fw fix',
                14,
            ],
            [
                'String pipe validation rules are not allowed in v3. Use typed Rule objects: [new Required, new MinLength(3)]. Run: php fw fix',
                14,
            ],
        ]);
    }

    public function testAllowsTypedRules(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/TypedValidation.php'], []);
    }
}
