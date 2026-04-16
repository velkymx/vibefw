<?php

declare(strict_types=1);

namespace Fw\Aux\Mcp;

use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;

final readonly class McpProtocol
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function __construct(
        private ToolRegistry $registry,
        private string $serverName = 'VibeFW AUX',
        private string $serverVersion = '3.0',
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
                'name' => $this->serverName,
                'version' => $this->serverVersion,
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

            // Handler threw — MCP spec: return success with isError:true, not JSON-RPC error
            $errorResult = WorkflowResult::error($error->getMessage());

            return $this->response($message->id, [
                'content' => $errorResult->toMcpContent(),
                'isError' => true,
            ]);
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
