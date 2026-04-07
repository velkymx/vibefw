<?php

declare(strict_types=1);

namespace Fw\Schema;

use Fw\Support\Result;

/**
 * Validates resource schema definitions against the fw://resource-schema spec.
 *
 * Returns prescriptive error messages that suggest the exact fix.
 */
final class ResourceSchemaValidator
{
    private const array VALID_TYPES = [
        'string', 'text', 'integer', 'boolean',
        'timestamp', 'date', 'decimal', 'foreignId', 'json',
    ];

    private const array VALID_MODIFIERS = [
        'type', 'required', 'nullable', 'unique', 'default',
        'length', 'constrained', 'onDelete', 'index',
    ];

    private const array FOREIGN_ONLY_MODIFIERS = ['constrained', 'onDelete'];

    /**
     * Validate a JSON string and return a ResourceSchema or error.
     *
     * @return Result<ResourceSchema, string>
     */
    public static function validateJson(string $json): Result
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return Result::err('Invalid JSON: ' . json_last_error_msg());
        }

        return self::validate($data);
    }

    /**
     * Validate a schema data array and return a ResourceSchema or error.
     *
     * @return Result<ResourceSchema, string>
     */
    public static function validate(array $data): Result
    {
        if (!isset($data['model']) || !is_string($data['model']) || $data['model'] === '') {
            return Result::err('Missing required "model" field. Add "model": "YourModelName" to the schema.');
        }

        if (!isset($data['table']) || !is_string($data['table']) || $data['table'] === '') {
            return Result::err('Missing required "table" field. Add "table": "your_table_name" to the schema.');
        }

        if (!isset($data['fields']) || !is_array($data['fields'])) {
            return Result::err('Missing required "fields" object. Add "fields": { "name": { "type": "string" } } to the schema.');
        }

        if (count($data['fields']) === 0) {
            return Result::err('Schema must define at least one field. Add fields to the "fields" object.');
        }

        foreach ($data['fields'] as $fieldName => $fieldDef) {
            $error = self::validateField($fieldName, $fieldDef);
            if ($error !== null) {
                return Result::err($error);
            }
        }

        return Result::ok(new ResourceSchema(
            model: $data['model'],
            table: $data['table'],
            api: (bool) ($data['api'] ?? false),
            fields: $data['fields'],
        ));
    }

    private static function validateField(string $name, mixed $def): ?string
    {
        if (!is_array($def)) {
            return "Field \"{$name}\": definition must be an object. Example: \"{$name}\": { \"type\": \"string\" }";
        }

        if (!isset($def['type'])) {
            return "Field \"{$name}\": missing \"type\". Add \"type\": \"string\" (or another valid type).";
        }

        if (!in_array($def['type'], self::VALID_TYPES, true)) {
            $suggestion = self::suggestType($def['type']);
            $validList = implode(', ', self::VALID_TYPES);
            $msg = "Field \"{$name}\": unknown type \"{$def['type']}\". Valid types: {$validList}.";
            if ($suggestion !== null) {
                $msg .= " Did you mean: \"{$suggestion}\"?";
            }
            return $msg;
        }

        // Check for unknown modifiers
        foreach (array_keys($def) as $key) {
            if (!in_array($key, self::VALID_MODIFIERS, true)) {
                $validList = implode(', ', self::VALID_MODIFIERS);
                return "Field \"{$name}\": unknown modifier \"{$key}\". Valid modifiers: {$validList}.";
            }
        }

        // Check foreign-only modifiers on non-foreignId fields
        foreach (self::FOREIGN_ONLY_MODIFIERS as $modifier) {
            if (isset($def[$modifier]) && $def['type'] !== 'foreignId') {
                return "Field \"{$name}\": \"{$modifier}\" is only valid for foreignId fields. Change type to \"foreignId\" or remove \"{$modifier}\".";
            }
        }

        return null;
    }

    private static function suggestType(string $input): ?string
    {
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::VALID_TYPES as $type) {
            $distance = levenshtein($input, $type);
            if ($distance < $bestDistance && $distance <= 3) {
                $bestDistance = $distance;
                $bestMatch = $type;
            }
        }

        return $bestMatch;
    }
}
