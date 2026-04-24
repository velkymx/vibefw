<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Exceptions\ToolValidationException;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Item #54: `ToolRegistry::validateInput()` had two type bugs:
 *   1. `'number'` was aliased to `'integer'`, so `is_int($value)` ran
 *      instead of `is_int || is_float`. JSON Schema `number` covers
 *      both, so valid floats got rejected.
 *   2. `'object'` was checked with `is_array()`, so list-style
 *      arrays (`[1,2,3]`) — clearly JSON arrays — validated as
 *      objects. JSON Schema `object` expects a key/value map.
 *
 * [R58] splits `number` to accept int + float, and tightens `object`
 * to `is_object($value)` OR an empty/assoc array (empty because JSON
 * `{}` decodes to `[]` under `json_decode(..., true)`, which is the
 * framework's default in `McpProtocol::handle()`).
 */
final class ToolRegistryTypeValidationTest extends TestCase
{
    private function registry(string $toolName, string $field, string $type): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: $toolName,
            description: 'type probe',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    $field => ['type' => $type],
                ],
            ],
            handler: static fn (array $input): WorkflowResult => WorkflowResult::success(),
        ));
        return $registry;
    }

    #[Test]
    public function numberAcceptsInt(): void
    {
        $result = $this->registry('t', 'amount', 'number')->call('t', ['amount' => 42]);
        $this->assertTrue($result->isOk(), 'int must satisfy JSON Schema number');
    }

    #[Test]
    public function numberAcceptsFloat(): void
    {
        $result = $this->registry('t', 'amount', 'number')->call('t', ['amount' => 3.14]);
        $this->assertTrue($result->isOk(), 'float must satisfy JSON Schema number');
    }

    #[Test]
    public function numberRejectsString(): void
    {
        $result = $this->registry('t', 'amount', 'number')->call('t', ['amount' => '42']);
        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(ToolValidationException::class, $result->unwrapErr());
    }

    #[Test]
    public function integerStillRejectsFloat(): void
    {
        $result = $this->registry('t', 'amount', 'integer')->call('t', ['amount' => 3.14]);
        $this->assertTrue($result->isErr(), 'integer must still reject float (distinct from number)');
    }

    #[Test]
    public function objectAcceptsStdClass(): void
    {
        $value = new stdClass();
        $value->key = 'value';

        $result = $this->registry('t', 'payload', 'object')->call('t', ['payload' => $value]);
        $this->assertTrue($result->isOk(), 'stdClass must satisfy JSON Schema object');
    }

    #[Test]
    public function objectAcceptsAssocArray(): void
    {
        // json_decode($s, true) yields assoc arrays for JSON objects —
        // the framework decodes that way, so accept keyed arrays too.
        $result = $this->registry('t', 'payload', 'object')
            ->call('t', ['payload' => ['foo' => 'bar']]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function objectAcceptsEmptyArrayAsEmptyJsonObject(): void
    {
        // JSON `{}` under json_decode(..., true) is `[]`. Reject would
        // break every caller that passes no fields.
        $result = $this->registry('t', 'payload', 'object')->call('t', ['payload' => []]);
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function objectRejectsListArray(): void
    {
        $result = $this->registry('t', 'payload', 'object')
            ->call('t', ['payload' => [1, 2, 3]]);
        $this->assertTrue($result->isErr(), 'list-style arrays are JSON arrays, not objects');
    }

    #[Test]
    public function objectRejectsScalarAndNull(): void
    {
        $stringResult = $this->registry('t', 'payload', 'object')->call('t', ['payload' => 'hi']);
        $intResult = $this->registry('t', 'payload', 'object')->call('t', ['payload' => 1]);
        $nullResult = $this->registry('t', 'payload', 'object')->call('t', ['payload' => null]);

        $this->assertTrue($stringResult->isErr());
        $this->assertTrue($intResult->isErr());
        $this->assertTrue($nullResult->isErr());
    }

    #[Test]
    public function arrayStillAcceptsList(): void
    {
        $result = $this->registry('t', 'items', 'array')->call('t', ['items' => [1, 2, 3]]);
        $this->assertTrue($result->isOk());
    }
}
