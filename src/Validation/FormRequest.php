<?php

declare(strict_types=1);

namespace Fw\Validation;

use Fw\Core\Request;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for form request validation with typed rule objects.
 *
 * Extend this class and implement rules() to define validation:
 *
 *     class CreatePostRequest extends FormRequest
 *     {
 *         public string $title;
 *         public string $body;
 *         public string $status;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'title'  => [new Required, new MinLength(3), new MaxLength(255)],
 *                 'body'   => [new Required],
 *                 'status' => [new InEnum(Status::class)],
 *             ];
 *         }
 *     }
 *
 * Usage in controller:
 *
 *     public function store(Request $request): Response
 *     {
 *         $validated = CreatePostRequest::fromRequest($request);
 *         // $validated->title, $validated->body, $validated->status are typed
 *     }
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
        $instance = new static();
        $validator = new Validator();
        $validated = $validator->validate($data, $instance->rules());

        foreach ($validated as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        $instance->afterValidation();

        return $instance;
    }

    /**
     * Try to create a validated request, returning null on failure.
     *
     * @param array<string, mixed> $data
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
     * Define validation rules using typed rule objects.
     *
     * @return array<string, list<Rule>>
     */
    abstract public function rules(): array;

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
     * Called after rule-based validation passes.
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
