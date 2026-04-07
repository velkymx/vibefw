<?php

declare(strict_types=1);

namespace Fw\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects string pipe validation rules like 'required|min:3'.
 *
 * In v3, validation must use typed Rule objects:
 *   [new Required, new MinLength(3)]
 *
 * @implements Rule<Array_>
 */
final class NoStringPipeValidationRule implements Rule
{
    public function getNodeType(): string
    {
        return Array_::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            // Check if value is a string containing pipe-separated rules
            if ($item->value instanceof String_) {
                $value = $item->value->value;

                // Match patterns like 'required|min:3' or 'required|email'
                if (preg_match('/^(required|nullable|string|integer|email|min|max|unique|exists|confirmed|in)\|/i', $value)
                    || preg_match('/\|(required|nullable|string|integer|email|min|max|unique|exists|confirmed|in)/i', $value)) {
                    $errors[] = RuleErrorBuilder::message(
                        "String pipe validation rules are not allowed in v3. "
                        . "Use typed Rule objects: [new Required, new MinLength(3)]. "
                        . "Run: php fw fix"
                    )->identifier('fw.stringPipeValidation')->build();
                }
            }
        }

        return $errors;
    }
}
