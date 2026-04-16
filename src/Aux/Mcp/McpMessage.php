<?php

declare(strict_types=1);

namespace Fw\Aux\Mcp;

final readonly class McpMessage
{
    public function __construct(
        public ?string $jsonrpc,
        public ?int $id,
        public ?string $method,
        public ?array $params,
        public ?array $result,
        public ?McpError $error,
    ) {}

    public static function request(int $id, string $method, ?array $params = null): self
    {
        return new self(
            jsonrpc: '2.0',
            id: $id,
            method: $method,
            params: $params,
            result: null,
            error: null,
        );
    }

    public static function notification(string $method, ?array $params = null): self
    {
        return new self(
            jsonrpc: '2.0',
            id: null,
            method: $method,
            params: $params,
            result: null,
            error: null,
        );
    }

    public static function response(int $id, mixed $result): self
    {
        return new self(
            jsonrpc: '2.0',
            id: $id,
            method: null,
            params: null,
            result: $result,
            error: null,
        );
    }

    public static function errorResponse(int $id, McpError $error): self
    {
        return new self(
            jsonrpc: '2.0',
            id: $id,
            method: null,
            params: null,
            result: null,
            error: $error,
        );
    }

    public static function fromArray(array $data): self
    {
        $jsonrpc = $data['jsonrpc'] ?? null;
        $id = $data['id'] ?? null;
        $method = $data['method'] ?? null;
        $params = $data['params'] ?? null;
        $result = $data['result'] ?? null;
        $error = isset($data['error'])
            ? ($data['error'] instanceof McpError
                ? $data['error']
                : new McpError(
                    $data['error']['code'] ?? -32700,
                    $data['error']['message'] ?? 'Unknown error',
                    $data['error']['data'] ?? null,
                ))
            : null;

        return new self(
            jsonrpc: $jsonrpc,
            id: $id,
            method: $method,
            params: $params,
            result: $result,
            error: $error,
        );
    }

    public function isRequest(): bool
    {
        return $this->id !== null && $this->method !== null;
    }

    public function isNotification(): bool
    {
        return $this->id === null && $this->method !== null;
    }

    public function isResponse(): bool
    {
        return $this->id !== null && ($this->result !== null || $this->error !== null);
    }

    public function toArray(): array
    {
        $arr = [];

        if ($this->jsonrpc !== null) {
            $arr['jsonrpc'] = $this->jsonrpc;
        }

        if ($this->id !== null) {
            $arr['id'] = $this->id;
        }

        if ($this->method !== null) {
            $arr['method'] = $this->method;
        }

        if ($this->params !== null) {
            $arr['params'] = $this->params;
        }

        if ($this->result !== null) {
            $arr['result'] = $this->result;
        }

        if ($this->error !== null) {
            $arr['error'] = $this->error->toArray();
        }

        return $arr;
    }
}
