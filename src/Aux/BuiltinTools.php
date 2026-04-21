<?php

declare(strict_types=1);

namespace Fw\Aux;

use Fw\Aux\Http\RouteAdvertiser;
use stdClass;

final class BuiltinTools
{
    public static function listRoutes(RouteAdvertiser $advertiser): Tool
    {
        return new Tool(
            name: 'list_routes',
            description: 'List all named HTTP routes in this app: name, method, path, handler, middleware. Agents use this to discover the human-facing feature surface.',
            inputSchema: [
                'type' => 'object',
                'properties' => new stdClass(),
            ],
            handler: function (array $input) use ($advertiser): WorkflowResult {
                $routes = $advertiser->advertise();

                return WorkflowResult::success(
                    completed: $routes,
                    metadata: ['count' => count($routes)],
                );
            },
            abilities: ['routes:read'],
            annotations: [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        );
    }

    public static function listFeatures(FeatureIndex $index): Tool
    {
        return new Tool(
            name: 'list_features',
            description: 'List all navigable features in this app with their URLs and required abilities. Agents should call this once to build a mental map before invoking other tools.',
            inputSchema: [
                'type' => 'object',
                'properties' => new stdClass(),
            ],
            handler: fn (array $input): WorkflowResult => WorkflowResult::success(
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
