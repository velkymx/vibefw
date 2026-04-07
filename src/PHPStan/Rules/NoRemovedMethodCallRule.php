<?php

declare(strict_types=1);

namespace Fw\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Detects calls to removed Result/Option methods.
 *
 * Removed in v3:
 *   Result: unwrap(), getValue(), getError()
 *   Option: unwrap()
 *
 * Use match() instead for exhaustive handling.
 *
 * @implements Rule<MethodCall>
 */
final class NoRemovedMethodCallRule implements Rule
{
    /** @var array<string, array<string, string>> class => [method => fix] */
    private const array REMOVED_METHODS = [
        'Fw\\Support\\Result' => [
            'unwrap' => 'Use ->match(ok: ..., err: ...) for exhaustive handling',
            'getValue' => 'Use ->match(ok: ..., err: ...) for exhaustive handling',
            'getError' => 'Use ->match(ok: ..., err: ...) for exhaustive handling',
        ],
        'Fw\\Support\\Option' => [
            'unwrap' => 'Use ->match(some: ..., none: ...) or ->unwrapOr($default)',
        ],
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $callerType = $scope->getType($node->var);

        foreach (self::REMOVED_METHODS as $className => $methods) {
            if (!isset($methods[$methodName])) {
                continue;
            }

            $targetType = new ObjectType($className);

            if ($targetType->isSuperTypeOf($callerType)->yes()) {
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            '%s::%s() was removed in v3. %s',
                            $className,
                            $methodName,
                            $methods[$methodName]
                        )
                    )->identifier('fw.removedMethod')->build(),
                ];
            }
        }

        return [];
    }
}
