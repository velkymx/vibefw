<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Schema\ResourceSchema;
use Fw\Schema\ResourceSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResourceSchemaTest extends TestCase
{
    // ==========================================
    // ResourceSchema VALUE OBJECT
    // ==========================================

    #[Test]
    public function schemaExposesModelName(): void
    {
        $schema = $this->makeSchema(['model' => 'Post', 'table' => 'posts', 'fields' => []]);

        $this->assertSame('Post', $schema->model);
    }

    #[Test]
    public function schemaExposesTableName(): void
    {
        $schema = $this->makeSchema(['model' => 'Post', 'table' => 'posts', 'fields' => []]);

        $this->assertSame('posts', $schema->table);
    }

    #[Test]
    public function schemaExposesApiFlag(): void
    {
        $schema = $this->makeSchema(['model' => 'Post', 'table' => 'posts', 'api' => true, 'fields' => []]);

        $this->assertTrue($schema->api);
    }

    #[Test]
    public function schemaApiFlagDefaultsToFalse(): void
    {
        $schema = $this->makeSchema(['model' => 'Post', 'table' => 'posts', 'fields' => []]);

        $this->assertFalse($schema->api);
    }

    #[Test]
    public function schemaExposesFields(): void
    {
        $schema = $this->makeSchema([
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'length' => 255, 'required' => true],
                'content' => ['type' => 'text', 'required' => true],
            ],
        ]);

        $this->assertCount(2, $schema->fields);
        $this->assertArrayHasKey('title', $schema->fields);
        $this->assertSame('string', $schema->fields['title']['type']);
        $this->assertSame(255, $schema->fields['title']['length']);
        $this->assertTrue($schema->fields['title']['required']);
    }

    #[Test]
    public function schemaFieldsPreserveAllModifiers(): void
    {
        $schema = $this->makeSchema([
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'slug' => ['type' => 'string', 'unique' => true, 'length' => 100, 'required' => true],
                'user_id' => ['type' => 'foreignId', 'constrained' => true, 'onDelete' => 'cascade'],
            ],
        ]);

        $this->assertTrue($schema->fields['slug']['unique']);
        $this->assertSame(100, $schema->fields['slug']['length']);
        $this->assertTrue($schema->fields['user_id']['constrained']);
        $this->assertSame('cascade', $schema->fields['user_id']['onDelete']);
    }

    // ==========================================
    // ResourceSchemaValidator - VALID SCHEMAS
    // ==========================================

    #[Test]
    public function validSchemaPassesValidation(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'length' => 255, 'required' => true],
                'content' => ['type' => 'text', 'required' => true],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isOk());
        $schema = $result->unwrapOr(null);
        $this->assertInstanceOf(ResourceSchema::class, $schema);
        $this->assertSame('Post', $schema->model);
    }

    #[Test]
    public function validSchemaWithAllFieldTypes(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Article',
            'table' => 'articles',
            'fields' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'views' => ['type' => 'integer'],
                'published' => ['type' => 'boolean'],
                'published_at' => ['type' => 'timestamp'],
                'event_date' => ['type' => 'date'],
                'price' => ['type' => 'decimal'],
                'user_id' => ['type' => 'foreignId', 'constrained' => true],
                'metadata' => ['type' => 'json'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function validSchemaWithApiFlag(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'api' => true,
            'fields' => [
                'title' => ['type' => 'string'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrapOr(null)->api);
    }

    // ==========================================
    // ResourceSchemaValidator - INVALID SCHEMAS
    // ==========================================

    #[Test]
    public function rejectsMissingModelName(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'table' => 'posts',
            'fields' => ['title' => ['type' => 'string']],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('model', $result->unwrapErr());
    }

    #[Test]
    public function rejectsMissingTableName(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'fields' => ['title' => ['type' => 'string']],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('table', $result->unwrapErr());
    }

    #[Test]
    public function rejectsMissingFields(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('fields', $result->unwrapErr());
    }

    #[Test]
    public function rejectsEmptyFields(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('field', $result->unwrapErr());
    }

    #[Test]
    public function rejectsUnknownFieldType(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'strng'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertStringContainsString('strng', $error);
        $this->assertStringContainsString('string', $error); // suggests correction
    }

    #[Test]
    public function rejectsFieldWithoutType(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['required' => true],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('type', $result->unwrapErr());
    }

    #[Test]
    public function rejectsUnknownModifier(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'invalid_modifier' => true],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('invalid_modifier', $result->unwrapErr());
    }

    #[Test]
    public function rejectsConstrainedOnNonForeignId(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'constrained' => true],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('constrained', $result->unwrapErr());
    }

    #[Test]
    public function rejectsOnDeleteOnNonForeignId(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'onDelete' => 'cascade'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('onDelete', $result->unwrapErr());
    }

    #[Test]
    public function prescriptiveErrorSuggestsCorrectType(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'strig'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        // Should suggest the closest valid type
        $this->assertStringContainsString('string', $error);
    }

    #[Test]
    public function prescriptiveErrorListsValidTypes(): void
    {
        $data = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'xyz'],
            ],
        ];

        $result = ResourceSchemaValidator::validate($data);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertStringContainsString('Valid types:', $error);
    }

    // ==========================================
    // ResourceSchemaValidator - FROM JSON STRING
    // ==========================================

    #[Test]
    public function validateJsonStringParsesAndValidates(): void
    {
        $json = json_encode([
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $result = ResourceSchemaValidator::validateJson($json);

        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function validateJsonRejectsInvalidJson(): void
    {
        $result = ResourceSchemaValidator::validateJson('{invalid json');

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('JSON', $result->unwrapErr());
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function makeSchema(array $data): ResourceSchema
    {
        return new ResourceSchema(
            model: $data['model'],
            table: $data['table'],
            api: $data['api'] ?? false,
            fields: $data['fields'],
        );
    }
}
