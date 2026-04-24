<?php

declare(strict_types=1);

namespace Fw\Validation;

use Fw\Database\Connection;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Validates data against rules defined as PHP 8 attributes.
 *
 * Supports both class-based validation (using attributes on properties)
 * and array-based validation (using Rule instances directly).
 *
 * Usage with attributes:
 *     class CreateUserRequest {
 *         #[Required]
 *         #[Email]
 *         public string $email;
 *
 *         #[Required]
 *         #[Min(8)]
 *         public string $password;
 *     }
 *
 *     $validator = new Validator();
 *     $validator->validateClass($data, CreateUserRequest::class);
 *
 * Usage with array rules:
 *     $validator->validate($data, [
 *         'email' => [new Required(), new Email()],
 *         'password' => [new Required(), new Min(8)],
 *     ]);
 */
final class Validator
{
    /**
     * Cache for reflection-based rule extraction.
     * @var array<string, array<string, array<Rule>>>
     */
    private static array $ruleCache = [];

    /**
     * Request-scoped Connection used by DatabaseRule subclasses. When
     * null (the default), resolveDbRule() falls back to the legacy
     * Connection::getInstance() path for backwards compatibility with
     * callers that haven't been wired through DI yet.
     */
    public function __construct(private ?Connection $connection = null)
    {
    }

    /**
     * Clear the rule cache (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$ruleCache = [];
    }

    /**
     * Validate data using rules defined as attributes on a class.
     *
     * @param array<string, mixed> $data
     * @param class-string $class
     * @return array<string, mixed> Validated data
     * @throws ValidationException
     */
    public function validateClass(array $data, string $class): array
    {
        $rules = $this->extractRulesFromClass($class);
        return $this->validate($data, $rules);
    }

    /**
     * Validate data using explicit rule arrays.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<Rule>> $rules
     * @return array<string, mixed> Validated data (only fields with rules)
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldErrors = [];

            foreach ($fieldRules as $rule) {
                $error = $rule instanceof DatabaseRule
                    ? $this->resolveDbRule($rule, $value, $this->formatFieldName($field), $data)
                    : $rule->validate($value, $this->formatFieldName($field), $data);
                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            } else {
                // Only include fields that passed validation
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * Validate data and return a result object instead of throwing.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<Rule>> $rules
     * @return ValidationResult
     */
    public function tryValidate(array $data, array $rules): ValidationResult
    {
        try {
            $validated = $this->validate($data, $rules);
            return new ValidationResult(true, $validated, []);
        } catch (ValidationException $e) {
            return new ValidationResult(false, [], $e->errors);
        }
    }

    /**
     * Extract validation rules from class property attributes.
     *
     * @param class-string $class
     * @return array<string, array<Rule>>
     */
    private function extractRulesFromClass(string $class): array
    {
        if (isset(self::$ruleCache[$class])) {
            return self::$ruleCache[$class];
        }

        $rules = [];
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyRules = [];

            foreach ($property->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance instanceof Rule) {
                    $propertyRules[] = $instance;
                }
            }

            if (!empty($propertyRules)) {
                $rules[$property->getName()] = $propertyRules;
            }
        }

        return self::$ruleCache[$class] = $rules;
    }

    /**
     * Resolve a DatabaseRule using the active Connection singleton.
     */
    /** @param array<string, mixed> $data */
    private function resolveDbRule(DatabaseRule $rule, mixed $value, string $field, array $data): ?string
    {
        $connection = $this->connection;
        if ($connection === null) {
            try {
                $connection = Connection::getInstance();
            } catch (LogicException $e) {
                throw new RuntimeException(
                    'A database-backed validation rule requires an active database connection. ' .
                    $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $rule->resolveWithDb($connection, $value, $field, $data);
    }

    /**
     * Format a field name for display in error messages.
     */
    private function formatFieldName(string $field): string
    {
        // Convert snake_case or camelCase to words
        $field = preg_replace('/[_-]/', ' ', $field);
        $field = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);
        return strtolower($field);
    }
}
