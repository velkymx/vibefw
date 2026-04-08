<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan;

use Fw\PHPStan\Rules\NoServiceLocationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoServiceLocationRule>
 */
final class NoServiceLocationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoServiceLocationRule();
    }

    public function testDetectsContainerGetInstance(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/ServiceLocationViolation.php'], [
            [
                'Service location via Container::getInstance() is not allowed. Use constructor injection instead.',
                13,
            ],
        ]);
    }

    public function testAllowsConstructorInjection(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/ConstructorInjection.php'], []);
    }
}
