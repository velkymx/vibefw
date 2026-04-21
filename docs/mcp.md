# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw serve:mcp` — start the stdio MCP server (point Claude Desktop / Cursor / any MCP client at `php /path/to/app/fw serve:mcp`)
- `php fw serve:mcp --host 0.0.0.0 --port 8080` — dev-time variant with network binding
- `php fw aux:list` — confirm which tools will appear in `tools/list`
- `php fw aux:schema <tool_name>` — dump the exact MCP shape that will go on the wire
- `php fw aux:call <tool_name> --input='{...}'` — invoke a tool locally without the MCP round trip (useful for debugging handler errors)
- `php fw make:tool <Name>` — scaffold a new tool that becomes available over MCP automatically

# BEWARE

Only read past here if you are unable to use the CLI.

# MCP — Model Context Protocol

VibeFW implements the [Model Context Protocol](https://modelcontextprotocol.io/) (version 2024-11-05) for AI agent connections. MCP lets Claude Desktop, Cursor, and other MCP-compatible clients discover and call your app's workflow tools.

## Setup: Claude Desktop

Add to `~/.claude/mcp_servers.json`:

```json
{
  "myapp": {
    "command": "php",
    "args": ["/path/to/your/app/fw", "serve:mcp"]
  }
}
```

Restart Claude Desktop. Your tools appear in Claude's tool list.

## Setup: Cursor

Add to your workspace `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "myapp": {
      "command": "php",
      "args": ["fw", "serve:mcp"],
      "cwd": "/path/to/your/app"
    }
  }
}
```

## Setup: Claude Code

Add to `~/.claude/mcp_servers.json` or your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "myapp": {
      "command": "php",
      "args": ["/path/to/your/app/fw", "serve:mcp"]
    }
  }
}
```

## CLI: serve:mcp

```bash
php fw serve:mcp
```

Starts a JSON-RPC 2.0 server on stdin/stdout. The MCP client sends requests as newline-delimited JSON, and the server responds on stdout.

The command resolves `McpServer` from the service container, so all tools registered in your `AuxServiceProvider` are available.

## Verify Before Connecting

Before connecting an MCP client, verify your tools are registered and their schemas are correct:

```bash
php fw aux:list                           # See all tools
php fw aux:schema process_ticket_queue    # Check JSON Schema + annotations
php fw aux:call process_ticket_queue --input '{"queue_id": 1}'  # Test handler
```

## Protocol Flow

```
Client                          Server
  │                               │
  │── initialize ────────────────▶│
  │◀── serverInfo + capabilities ─│
  │                               │
  │── notifications/initialized ─▶│  (no response)
  │                               │
  │── tools/list ────────────────▶│
  │◀── array of tool shapes ──────│
  │                               │
  │── tools/call {name, args} ──▶│
  │◀── {content, isError?} ───────│
```

### initialize

```json
→ {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}
← {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2024-11-05","serverInfo":{"name":"VibeFW AUX","version":"3.0"},"capabilities":{"tools":{}}}}
```

Server name and version are configurable via `config/aux.php` (`mcp_server_name`, `mcp_server_version`).

### tools/list

```json
→ {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}
← {"jsonrpc":"2.0","id":2,"result":{"tools":[
    {
      "name":"process_ticket_queue",
      "description":"Process all open tickets in a queue, resolve or escalate each.",
      "inputSchema":{"type":"object","required":["queue_id"],"properties":{"queue_id":{"type":"integer","description":"Queue to process"}}},
      "annotations":{"idempotentHint":true}
    }
  ]}}
```

Tools are filtered by caller abilities. Public tools (`abilities: []`) always appear. Tools with annotations include them in the shape; tools without annotations omit the field.

### tools/call

```json
→ {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"process_ticket_queue","arguments":{"queue_id":147}}}
← {"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"completed\":[{\"id\":301,\"action\":\"resolved\"}],\"failed\":[],\"pending\":[],\"metadata\":{\"duration_ms\":234.56}}"}]}}
```

Content items use `type: "text"` per MCP spec §5.10. The `text` field contains a JSON-encoded `WorkflowResult`. The `duration_ms` in metadata is automatically captured by the framework.

### Error Handling

| Scenario | Response |
|----------|----------|
| Malformed JSON | JSON-RPC error, code -32700 |
| Unknown method | JSON-RPC error, code -32601 |
| Tool not found | JSON-RPC error, code -32001 |
| Input validation failed | JSON-RPC error, code -32002 |
| Handler exception | JSON-RPC **success** with `isError: true` |
| Batch requests | JSON-RPC error, code -32600 (not supported) |
| Notification (no id) | No response (per JSON-RPC spec) |

Handler exceptions return a JSON-RPC success (not error) per MCP spec — the tool was found and executed, but the execution failed:

```json
{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"metadata\":{\"error\":\"Database timeout\"}}"}],"isError":true}}
```

## HTTP+SSE Transport

For web-based MCP clients that can't use stdio:

```
GET  /mcp/sse        → Opens SSE stream
POST /mcp/messages   → Accepts JSON-RPC requests
```

The SSE endpoint sends an `endpoint` event pointing to `/mcp/messages`, then sends `: ping` keepalive comments at the configured heartbeat interval (`aux.sse_heartbeat_seconds`, default 15s).

Both routes are protected by `AgentMiddleware` (requires `Accept: application/json`). Ability gating happens inside `ToolRegistry` — public tools are visible without authentication.

These routes are only registered when `aux.mcp_enabled` is `true` (the default). Set `AUX_MCP_ENABLED=false` in `.env` to disable.

## Input Validation

`ToolRegistry` performs lightweight JSON Schema validation:

- **`required`**: Checks that required properties are present
- **Scalar `type`**: Validates `string`, `integer`, `number`, `boolean`, `array`, `object`, `null`

For complex validation, do it inside your handler or workflow:

```php
handler: function (array $input) {
    $validated = StoreTicketRequest::fromArray($input);
    return (new ProcessWorkflow())->execute($validated->toArray());
},
```

## Testing Your Tools

Three layers, from simplest to most integrated:

```php
// 1. Handler directly — fastest, no validation or protocol overhead
$tool = ProcessTicketQueueTool::make();
$result = ($tool->handler)(['queue_id' => 147]);
$this->assertFalse($result->isError);
$this->assertNotEmpty($result->completed);

// 2. Via registry — includes validation, duration capture, events
$registry = new ToolRegistry();
$registry->register($tool);
$result = $registry->call('process_ticket_queue', ['queue_id' => 147]);
$this->assertTrue($result->isOk());

// 3. Via protocol — full JSON-RPC round-trip, exactly what an MCP client sees
$protocol = new McpProtocol($registry);
$json = $protocol->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"process_ticket_queue","arguments":{"queue_id":147}}}');
$decoded = json_decode($json, true);
$this->assertArrayHasKey('content', $decoded['result']);
```

## Disabling MCP

Set in `.env`:

```env
AUX_MCP_ENABLED=false
```

This prevents `AuxServiceProvider` from registering `/mcp/*` routes entirely. The HTTP Agent API (`/agent/*`) remains available independently via `AUX_HTTP_AGENT_ENABLED`.

## See Also

- [aux.md](aux.md) — AUX design principles, full developer guide, CLI tools, events, composability
- [authentication.md](authentication.md) — API token auth for /agent/* endpoints
