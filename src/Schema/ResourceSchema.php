<?php

declare(strict_types=1);

namespace Fw\Schema;

/**
 * Value object representing a validated resource schema.
 *
 * Created by ResourceSchemaValidator after successful validation.
 */
final readonly class ResourceSchema
{
    /**
     * @param string $model PascalCase model name
     * @param string $table snake_case table name
     * @param bool $api Whether this is an API-only resource
     * @param array<string, array<string, mixed>> $fields Field definitions keyed by name
     */
    public function __construct(
        public string $model,
        public string $table,
        public bool $api,
        public array $fields,
    ) {}
}
