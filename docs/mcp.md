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

## CLI: serve:mcp

```bash
php fw serve:mcp
```

Starts a JSON-RPC 2.0 server on stdin/stdout. The MCP client sends requests as newline-delimited JSON, and the server responds on stdout.

The command resolves `McpServer` from the service container, so all tools registered in your `AuxServiceProvider` are available.

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
← {"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"process_ticket_queue","description":"Process all open tickets","inputSchema":{"type":"object","required":["queue_id"],"properties":{"queue_id":{"type":"integer"}}}}]}}
```

Tools are filtered by caller abilities. Public tools (abilities: []) always appear.

### tools/call

```json
→ {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"process_ticket_queue","arguments":{"queue_id":147}}}
← {"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"completed\":[...],\"failed\":[...]}"}]}}
```

Content items use `type: "text"` per MCP spec §5.10. The `text` field contains a JSON-encoded `WorkflowResult`.

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

These routes are unauthenticated at the HTTP level. Ability gating happens inside `ToolRegistry` — public tools are visible without authentication.

## Input Validation

`ToolRegistry` performs lightweight JSON Schema validation:

- **`required`**: Checks that required properties are present
- **Scalar `type`**: Validates `string`, `integer`, `number`, `boolean`

For complex validation, do it inside your handler or workflow:

```php
handler: function (array $input) {
    $validated = StoreTicketRequest::fromArray($input);
    return (new ProcessWorkflow())->execute($validated->toArray());
},
```

## Testing Your Tools

```php
// Unit test — tool directly
$tool = ProcessTicketQueueTool::make();
$result = ($tool->handler)(['queue_id' => 147]);
$this->assertFalse($result->isError);
$this->assertNotEmpty($result->completed);

// Unit test — via registry
$registry = new ToolRegistry();
$registry->register($tool);
$result = $registry->call('process_ticket_queue', ['queue_id' => 147]);
$this->assertTrue($result->isOk());

// Unit test — via protocol
$protocol = new McpProtocol($registry);
$json = $protocol->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"process_ticket_queue","arguments":{"queue_id":147}}}');
$decoded = json_decode($json, true);
$this->assertArrayHasKey('content', $decoded['result']);
```

## See Also

- [aux.md](aux.md) — AUX design principles and full developer guide
- [authentication.md](authentication.md) — API token auth for /agent/* endpoints
