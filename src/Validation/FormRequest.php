<?php

declare(strict_types=1);

namespace Fw\Validation;

use Fw\Core\Request;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for form request validation with PHP 8 attributes.
 *
 * Extend this class to create typed, validated request objects:
 *
 *     class CreatePostRequest extends FormRequest
 *     {
 *         #[Required]
 *         #[Max(255)]
 *         public string $title;
 *
 *         #[Required]
 *         public string $body;
 *
 *         #[In(['draft', 'published'])]
 *         public string $status = 'draft';
 *     }
 *
 * Usage in controller:
 *
 *     public function store(Request $request): Response
 *     {
 *         $validated = CreatePostRequest::fromRequest($request);
 *         // $validated->title, $validated->body, $validated->status are typed
 *     }
 *
 * The validation happens automatically and throws ValidationException if it fails.
 */
abstract class FormRequest
{
    /**
     * Create a validated request from HTTP request data.
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request): static
    {
        return static::fromArray($request->all());
    }

    /**
     * Create a validated request from an array.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data): static
    {
        $validator = new Validator();
        $validated = $validator->validateClass($data, static::class);

        $instance = new static();
        foreach ($validated as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        // Run custom validation logic
        $instance->afterValidation();

        return $instance;
    }

    /**
     * Try to create a validated request, returning null on failure.
     *
     * @param array<string, mixed> $data
     * @return static|null
     */
    public static function tryFromArray(array $data): ?static
    {
        try {
            return static::fromArray($data);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Get all validated data as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $data[$property->getName()] = $property->getValue($this);
            }
        }

        return $data;
    }

    /**
     * Get only specific validated fields.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->toArray(), array_flip($keys));
    }

    /**
     * Get validated fields except specified ones.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    /**
     * Override to add custom validation logic.
     *
     * This is called after attribute-based validation passes.
     * Throw ValidationException to indicate custom validation failure.
     *
     * @throws ValidationException
     */
    protected function afterValidation(): void
    {
        // Override in subclass for custom validation
    }

    /**
     * Helper to fail with custom errors.
     *
     * @param array<string, array<string>> $errors
     * @throws ValidationException
     */
    protected function fail(array $errors): never
    {
        throw new ValidationException($errors);
    }
}
