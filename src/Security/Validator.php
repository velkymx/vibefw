<?php

declare(strict_types=1);

namespace Fw\Security;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use ValueError;

final class Validator
{
    private const array MESSAGES = [
        'required' => 'The :field field is required.',
        'email' => 'The :field field must be a valid email address.',
        'url' => 'The :field field must be a valid URL.',
        'min' => 'The :field field must be at least :param characters.',
        'max' => 'The :field field must not exceed :param characters.',
        'between' => 'The :field field must be between :param characters.',
        'numeric' => 'The :field field must be numeric.',
        'integer' => 'The :field field must be an integer.',
        'alpha' => 'The :field field must contain only letters.',
        'alphanumeric' => 'The :field field must contain only letters and numbers.',
        'regex' => 'The :field field format is invalid.',
        'in' => 'The :field field must be one of: :param.',
        'notIn' => 'The :field field must not be one of: :param.',
        'confirmed' => 'The :field confirmation does not match.',
        'unique' => 'The :field has already been taken.',
        'exists' => 'The selected :field is invalid.',
        'date' => 'The :field field must be a valid date.',
        'before' => 'The :field field must be a date before :param.',
        'after' => 'The :field field must be a date after :param.',
        'array' => 'The :field field must be an array.',
        'boolean' => 'The :field field must be true or false.',
        'same' => 'The :field field must match :param.',
        'different' => 'The :field field must be different from :param.',
        'file' => 'The :field field must be a file.',
        'image' => 'The :field field must be an image.',
        'mimes' => 'The :field field must be a file of type: :param.',
        'size' => 'The :field field must be :param kilobytes.',
    ];

    private array $data;

    private array $rules;

    private array $errors = [];

    private array $validated = [];

    private bool $throwOnFailure = false;

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /**
     * Enable PHP 8.5 filter exception mode.
     * When enabled, filter_var will throw exceptions on validation failures.
     */
    public function throwOnFailure(bool $throw = true): self
    {
        $this->throwOnFailure = $throw;
        return $this;
    }

    /**
     * Validate and throw exception if validation fails.
     * Uses PHP 8.5's FILTER_FLAG_THROW_ON_FAILURE behavior pattern.
     *
     * @throws ValidationException
     */
    public function validateOrFail(): array
    {
        if (!$this->validate()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    public function validate(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $rules) {
            $this->validateField($field, $rules);
        }

        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->validate();
    }

    public function passes(): bool
    {
        return $this->validate();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }

        foreach ($this->errors as $errors) {
            return $errors[0] ?? null;
        }

        return null;
    }

    public function allErrors(): array
    {
        $all = [];

        foreach ($this->errors as $errors) {
            $all = array_merge($all, $errors);
        }

        return $all;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    private function validateField(string $field, string|array $rules): void
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        $value = $this->getValue($field);

        $isRequired = in_array('required', $rules, true);
        $isEmpty = $value === null || $value === '' || $value === [];

        if (!$isRequired && $isEmpty) {
            return;
        }

        foreach ($rules as $rule) {
            $this->applyRule($field, $value, $rule);
        }

        if (!isset($this->errors[$field])) {
            $this->validated[$field] = $value;
        }
    }

    private function getValue(string $field): mixed
    {
        $keys = explode('.', $field);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Allowed `validate*` methods that map onto user-facing rule names.
     * Anything not in this set is rejected at apply time, so typos like
     * `requied` fail loudly instead of silently passing.
     *
     * Built lazily from MESSAGES so adding a rule message + matching
     * `validate{Name}()` method automatically extends the allowlist.
     *
     * @var array<string, true>|null
     */
    private static ?array $knownRules = null;

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        [$ruleName, $param] = array_pad(explode(':', $rule, 2), 2, null);

        if (!is_string($ruleName) || $ruleName === '') {
            throw new InvalidArgumentException(
                "Empty validation rule for field '{$field}' — check for accidental '||' "
                . "or trailing pipes in the rule string."
            );
        }

        if (!isset(self::knownRules()[$ruleName])) {
            throw new InvalidArgumentException(
                "Unknown validation rule '{$ruleName}' for field '{$field}'. "
                . 'Known rules: ' . implode(', ', array_keys(self::knownRules())) . '.'
            );
        }

        $method = 'validate' . ucfirst($ruleName);

        if (!$this->$method($value, $param, $field)) {
            $this->addError($field, $ruleName, $param);
        }
    }

    /**
     * Cross-check MESSAGES against actual validate*() methods; rule names
     * that are documented but unimplemented (e.g. 'unique', 'exists',
     * 'file', 'image', 'mimes', 'size') stay out of the allowlist so
     * callers learn at the first apply that the framework doesn't ship
     * an implementation, rather than silently passing.
     *
     * @return array<string, true>
     */
    private static function knownRules(): array
    {
        if (self::$knownRules !== null) {
            return self::$knownRules;
        }
        $known = [];
        foreach (array_keys(self::MESSAGES) as $name) {
            if (method_exists(self::class, 'validate' . ucfirst($name))) {
                $known[$name] = true;
            }
        }
        return self::$knownRules = $known;
    }

    private function addError(string $field, string $rule, ?string $param): void
    {
        $message = self::MESSAGES[$rule] ?? "The :field field is invalid.";

        // Escape field name to prevent XSS if error messages are rendered in HTML
        $safeField = htmlspecialchars(str_replace('_', ' ', $field), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = str_replace(':field', $safeField, $message);

        if ($param !== null) {
            // Also escape param as it could contain user input
            $safeParam = htmlspecialchars($param, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $message = str_replace(':param', $safeParam, $message);
        }

        $this->errors[$field][] = $message;
    }

    private function validateRequired(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return ! (is_array($value) && empty($value))

        ;
    }

    private function validateEmail(mixed $value): bool
    {
        // PHP 8.5: Can use FILTER_FLAG_THROW_ON_FAILURE for exception-based validation
        if ($this->throwOnFailure) {
            try {
                filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_THROW_ON_FAILURE);
                return true;
            } catch (ValueError) {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateUrl(mixed $value): bool
    {
        // PHP 8.5: Can use FILTER_FLAG_THROW_ON_FAILURE for exception-based validation
        if ($this->throwOnFailure) {
            try {
                filter_var($value, FILTER_VALIDATE_URL, FILTER_FLAG_THROW_ON_FAILURE);
                return true;
            } catch (ValueError) {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateMin(mixed $value, ?string $param): bool
    {
        $min = (int) $param;

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }

    private function validateMax(mixed $value, ?string $param): bool
    {
        $max = (int) $param;

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }

    private function validateBetween(mixed $value, ?string $param): bool
    {
        [$min, $max] = $this->parseBetweenParam($param);

        if (is_string($value)) {
            $length = mb_strlen($value);
            return $length >= $min && $length <= $max;
        }

        if (is_numeric($value)) {
            return $value >= $min && $value <= $max;
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseBetweenParam(?string $param): array
    {
        if ($param === null || $param === '') {
            throw new InvalidArgumentException('between rule requires a "min,max" parameter');
        }

        $parts = explode(',', $param);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new InvalidArgumentException(
                "between rule requires two numeric bounds, got \"{$param}\""
            );
        }

        $min = (int) $parts[0];
        $max = (int) $parts[1];
        if ($min > $max) {
            throw new InvalidArgumentException(
                "between rule min ({$min}) must not exceed max ({$max})"
            );
        }

        return [$min, $max];
    }

    private function validateNumeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    private function validateInteger(mixed $value): bool
    {
        // PHP 8.5: Can use FILTER_FLAG_THROW_ON_FAILURE for exception-based validation
        if ($this->throwOnFailure) {
            try {
                filter_var($value, FILTER_VALIDATE_INT, FILTER_FLAG_THROW_ON_FAILURE);
                return true;
            } catch (ValueError) {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateAlpha(mixed $value): bool
    {
        // Length guard prevents ReDoS with malformed UTF-8 sequences
        return is_string($value) && strlen($value) <= 65535 && preg_match('/^[\pL\pM]+$/u', $value) === 1;
    }

    private function validateAlphanumeric(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 65535 && preg_match('/^[\pL\pM\pN]+$/u', $value) === 1;
    }

    private function validateRegex(mixed $value, ?string $param): bool
    {
        if (!is_string($value) || $param === null) {
            return false;
        }

        // Validate the pattern itself before applying it.
        // Suppress warnings from invalid regex and check for PCRE errors.
        $result = @preg_match($param, $value);
        if ($result === false) {
            // Invalid regex pattern — treat as validation failure
            return false;
        }

        return $result > 0;
    }

    private function validateIn(mixed $value, ?string $param): bool
    {
        $allowed = explode(',', $param ?? '');
        return in_array($value, $allowed, true);
    }

    private function validateNotIn(mixed $value, ?string $param): bool
    {
        $disallowed = explode(',', $param ?? '');
        return !in_array($value, $disallowed, true);
    }

    private function validateConfirmed(mixed $value, ?string $param, string $field): bool
    {
        $confirmationField = $field . '_confirmation';
        return $value === ($this->data[$confirmationField] ?? null);
    }

    private function validateDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return $this->parseDate($value) !== null;
    }

    private function validateBefore(mixed $value, ?string $param): bool
    {
        if (!is_string($value) || $param === null) {
            return false;
        }

        $date = $this->parseDate($value);
        $before = $this->parseDate($param);

        return $date !== null && $before !== null && $date < $before;
    }

    private function validateAfter(mixed $value, ?string $param): bool
    {
        if (!is_string($value) || $param === null) {
            return false;
        }

        $date = $this->parseDate($value);
        $after = $this->parseDate($param);

        return $date !== null && $after !== null && $date > $after;
    }

    /**
     * Parse a date string to DateTimeImmutable.
     *
     * Uses DateTimeImmutable instead of deprecated strtotime() for
     * better type safety and forward compatibility.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        // Try common formats first for explicit parsing
        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:sP',
            'd/m/Y',
            'm/d/Y',
            'U', // Unix timestamp
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        // Fallback to natural language parsing
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function validateArray(mixed $value): bool
    {
        return is_array($value);
    }

    private function validateBoolean(mixed $value): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    private function validateSame(mixed $value, ?string $param): bool
    {
        return $value === ($this->data[$param] ?? null);
    }

    private function validateDifferent(mixed $value, ?string $param): bool
    {
        return $value !== ($this->data[$param] ?? null);
    }
}
