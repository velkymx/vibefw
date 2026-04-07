<?php

declare(strict_types=1);

namespace Fw\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Ensures controller methods that accept Request return Response.
 *
 * In v3, controllers must always return Response objects.
 * HttpKernel throws if a non-Response is returned.
 *
 * @implements Rule<ClassMethod>
 */
final class ControllerReturnTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null) {
            return [];
        }

        // Only check classes that extend Controller
        if (!$this->extendsController($classReflection)) {
            return [];
        }

        // Only check public methods (route handlers)
        if (!$node->isPublic()) {
            return [];
        }

        // Skip constructor
        if ($node->name->toString() === '__construct') {
            return [];
        }

        // Check if method accepts Request parameter
        $hasRequestParam = false;
        foreach ($node->params as $param) {
            $type = $param->type;
            if ($type instanceof Node\Name && str_contains($type->toString(), 'Request')) {
                $hasRequestParam = true;
                break;
            }
            if ($type instanceof Node\Identifier && $type->toString() === 'Request') {
                $hasRequestParam = true;
                break;
            }
        }

        if (!$hasRequestParam) {
            return [];
        }

        // Check return type
        $returnType = $node->getReturnType();

        if ($returnType === null) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Controller method %s::%s() accepts Request but has no return type. Must return Response.',
                        $classReflection->getName(),
                        $node->name->toString()
                    )
                )->identifier('fw.controllerReturnType')->build(),
            ];
        }

        $returnTypeName = $this->getTypeName($returnType);

        if ($returnTypeName !== null && !str_contains($returnTypeName, 'Response')) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Controller method %s::%s() must return Response, got %s.',
                        $classReflection->getName(),
                        $node->name->toString(),
                        $returnTypeName
                    )
                )->identifier('fw.controllerReturnType')->build(),
            ];
        }

        return [];
    }

    private function extendsController(ClassReflection $classReflection): bool
    {
        $parent = $classReflection->getParentClass();

        while ($parent !== null) {
            if ($parent->getName() === 'Fw\\Core\\Controller') {
                return true;
            }
            $parent = $parent->getParentClass();
        }

        return false;
    }

    private function getTypeName(Node $type): ?string
    {
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->getTypeName($t) ?? '?', $type->types));
        }

        return null;
    }
}
