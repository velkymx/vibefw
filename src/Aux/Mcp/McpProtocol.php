<?php

declare(strict_types=1);

namespace Fw\Aux\Mcp;

use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;

final readonly class McpProtocol
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'myapp';
    private const SERVER_VERSION = '1.0.0';

    public function __construct(
        private ToolRegistry $registry,
    ) {}

    public function handle(string $rawJson, array $callerAbilities = []): string
    {
        $data = json_decode($rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(null, McpError::parseError('Invalid JSON'));
        }

        if (is_array($data) && array_is_list($data)) {
            return $this->errorResponse(null, McpError::invalidRequest('Batch requests not supported'));
        }

        $message = McpMessage::fromArray($data);

        if ($message->isNotification()) {
            return '';
        }

        if ($message->id === null) {
            return $this->errorResponse(null, McpError::invalidRequest('Request must have id'));
        }

        if (!$message->isRequest()) {
            return $this->errorResponse($message->id, McpError::invalidRequest('Request must have id and method'));
        }

        return match ($message->method) {
            'initialize' => $this->handleInitialize($message),
            'tools/list' => $this->handleToolsList($message, $callerAbilities),
            'tools/call' => $this->handleToolsCall($message, $callerAbilities),
            default => $this->errorResponse($message->id, McpError::methodNotFound()),
        };
    }

    private function handleInitialize(McpMessage $message): string
    {
        return $this->response($message->id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
        ]);
    }

    private function handleToolsList(McpMessage $message, array $callerAbilities): string
    {
        $tools = $this->registry->allFor($callerAbilities);

        $shapes = array_values(array_map(
            fn($tool) => $tool->toMcpShape(),
            $tools,
        ));

        return $this->response($message->id, [
            'tools' => $shapes,
        ]);
    }

    private function handleToolsCall(McpMessage $message, array $callerAbilities): string
    {
        $params = $message->params ?? [];

        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if ($toolName === null) {
            return $this->errorResponse($message->id, McpError::invalidParams('Missing tool name'));
        }

        $result = $this->registry->call($toolName, $arguments, $callerAbilities);

        if ($result->isErr()) {
            $error = $result->unwrapErr();

            if ($error instanceof \Fw\Aux\Exceptions\ToolNotFoundException) {
                return $this->errorResponse($message->id, McpError::toolNotFound($toolName));
            }

            if ($error instanceof \Fw\Aux\Exceptions\ToolValidationException) {
                return $this->errorResponse($message->id, McpError::toolValidationError($error->getMessage()));
            }

            return $this->errorResponse($message->id, McpError::serverError($error->getMessage()));
        }

        $workflowResult = $result->unwrapOr(null);

        return $this->response($message->id, [
            'content' => $workflowResult->toMcpContent(),
        ]);
    }

    private function response(int $id, mixed $result): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function errorResponse(?int $id, McpError $error): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error->toArray(),
        ]);
    }
}
