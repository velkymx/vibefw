<?php

declare(strict_types=1);

namespace Fw\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects service locator anti-pattern in application code.
 *
 * Container::getInstance() should not be called directly.
 * Use constructor injection instead.
 *
 * @implements Rule<StaticCall>
 */
final class NoServiceLocationRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $className = $node->class->toString();

        // Resolve to fully qualified name
        if ($className === 'Container') {
            $className = 'Fw\\Core\\Container';
        }

        if ($className !== 'Fw\\Core\\Container') {
            return [];
        }

        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();

        if ($methodName !== 'getInstance') {
            return [];
        }

        // Allow in framework internals (not tests)
        $classReflection = $scope->getClassReflection();
        if ($classReflection !== null) {
            $name = $classReflection->getName();
            if (str_starts_with($name, 'Fw\\') && !str_starts_with($name, 'Fw\\Tests\\')) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'Service location via Container::getInstance() is not allowed. Use constructor injection instead.'
            )->identifier('fw.noServiceLocation')->build(),
        ];
    }
}
