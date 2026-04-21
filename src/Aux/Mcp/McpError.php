<?php

declare(strict_types=1);

namespace Fw\Aux\Mcp;

final readonly class McpError
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public const SERVER_ERROR = -32000;
    public const TOOL_NOT_FOUND = -32001;
    public const TOOL_VALIDATION_ERROR = -32002;

    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = null,
    ) {}

    public static function parseError(string $message = 'Parse error', mixed $data = null): self
    {
        return new self(self::PARSE_ERROR, $message, $data);
    }

    public static function invalidRequest(string $message = 'Invalid Request', mixed $data = null): self
    {
        return new self(self::INVALID_REQUEST, $message, $data);
    }

    public static function methodNotFound(string $message = 'Method not found', mixed $data = null): self
    {
        return new self(self::METHOD_NOT_FOUND, $message, $data);
    }

    public static function invalidParams(string $message = 'Invalid params', mixed $data = null): self
    {
        return new self(self::INVALID_PARAMS, $message, $data);
    }

    public static function internalError(string $message = 'Internal error', mixed $data = null): self
    {
        return new self(self::INTERNAL_ERROR, $message, $data);
    }

    public static function serverError(string $message = 'Server error', mixed $data = null): self
    {
        return new self(self::SERVER_ERROR, $message, $data);
    }

    public static function toolNotFound(string $toolName): self
    {
        return new self(
            self::TOOL_NOT_FOUND,
            "Tool '{$toolName}' not found",
            ['tool' => $toolName],
        );
    }

    public static function toolValidationError(string $message, mixed $data = null): self
    {
        return new self(self::TOOL_VALIDATION_ERROR, $message, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $arr['data'] = $this->data;
        }

        return $arr;
    }
}
