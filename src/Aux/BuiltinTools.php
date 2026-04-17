<?php

declare(strict_types=1);

namespace Fw\Aux;

final class BuiltinTools
{
    public static function listFeatures(FeatureIndex $index): Tool
    {
        return new Tool(
            name: 'list_features',
            description: 'List all navigable features in this app with their URLs and required abilities. Agents should call this once to build a mental map before invoking other tools.',
            inputSchema: [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            handler: fn(array $input): WorkflowResult => WorkflowResult::success(
                completed: $index->toArray(),
                metadata: ['count' => count($index->features)],
            ),
            abilities: [],
            annotations: [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        );
    }
}
