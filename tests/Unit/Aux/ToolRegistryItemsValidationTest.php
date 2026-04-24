<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Exceptions\ToolValidationException;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #57: `ToolRegistry::validateInput()` only validated the
 * top-level type of each field. For `type: array`, it never looked
 * at the `items` sub-schema — so a tool that declared
 * `["type" => "array", "items" => ["type" => "integer"]]` accepted
 * `["a", "b"]` silently and the handler had to re-check at runtime.
 *
 * [R61] adds item-level type checking: if `items.type` is declared
 * on an array property, every element is matched against that type
 * via the same `isValueValidType()` helper used for top-level
 * checks. Arrays without an `items` schema keep their previous
 * permissive behaviour — this is minimal JSON-Schema `items`
 * support, not full schema validation.
 */
final class ToolRegistryItemsValidationTest extends TestCase
{
    private function registry(array $itemsSchema): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 't',
            description: 'items probe',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'values' => [
                        'type' => 'array',
                        'items' => $itemsSchema,
                    ],
                ],
            ],
            handler: static fn (array $input): WorkflowResult => WorkflowResult::success(),
        ));
        return $registry;
    }

    #[Test]
    public function acceptsArrayOfCorrectlyTypedItems(): void
    {
        $result = $this->registry(['type' => 'integer'])->call('t', ['values' => [1, 2, 3]]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsArrayWithWrongTypedItem(): void
    {
        $result = $this->registry(['type' => 'integer'])->call('t', ['values' => [1, 'two', 3]]);
        $this->assertTrue($result->isErr());
        $err = $result->unwrapErr();
        $this->assertInstanceOf(ToolValidationException::class, $err);
        $this->assertStringContainsString('values', $err->getMessage());
    }

    #[Test]
    public function acceptsArrayOfStrings(): void
    {
        $result = $this->registry(['type' => 'string'])->call('t', ['values' => ['a', 'b']]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function acceptsEmptyArray(): void
    {
        $result = $this->registry(['type' => 'integer'])->call('t', ['values' => []]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function arrayWithoutItemsSchemaStillAcceptsMixedContent(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 'loose',
            description: 'no items schema',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'values' => ['type' => 'array'],
                ],
            ],
            handler: static fn (array $input): WorkflowResult => WorkflowResult::success(),
        ));

        $result = $registry->call('loose', ['values' => [1, 'mix', null, 3.14]]);
        $this->assertTrue($result->isOk(), 'no items schema → no per-element type check');
    }

    #[Test]
    public function acceptsArrayOfObjects(): void
    {
        $result = $this->registry(['type' => 'object'])->call('t', [
            'values' => [['k' => 'v'], ['m' => 'n']],
        ]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsNumberItemsThatAreStrings(): void
    {
        $result = $this->registry(['type' => 'number'])->call('t', ['values' => [1, 2.5, '3']]);
        $this->assertTrue($result->isErr());
    }
}
