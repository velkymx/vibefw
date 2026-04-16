# AUX — Agentic User Experience

AUX makes your VibeFW app usable by AI agents, not just reachable. Instead of exposing atomic API endpoints that force agents to chain 5+ calls, you define **workflow tools** — single operations that encapsulate an entire business process.

```
❌ Agent chains:  get_ticket → get_history → search_kb → draft_response → update_ticket → escalate
✅ Agent calls:   process_ticket_queue → structured result
```

## Quick Start

### 1. Generate a Tool + Workflow

```bash
php fw make:tool ProcessTicketQueue
php fw make:workflow ProcessTicketQueue
```

### 2. Implement the Workflow

```php
// app/Workflows/ProcessTicketQueueWorkflow.php
final class ProcessTicketQueueWorkflow extends WorkflowAction
{
    public function execute(array $input): WorkflowResult
    {
        $tickets = $this->query(new GetOpenTickets($input['queue_id']))
            ->unwrapOr([]);

        $completed = [];
        $failed = [];

        foreach ($tickets as $ticket) {
            $result = $this->dispatch(new ResolveTicket($ticket['id']));

            $result->match(
                ok: fn($r) => $completed[] = ['id' => $ticket['id'], 'action' => 'resolved', 'resolution' => $r],
                err: fn($e) => $failed[] = ['id' => $ticket['id'], 'error' => $e->getMessage()],
            );
        }

        return WorkflowResult::partial($completed, $failed, metadata: [
            'processed_at' => date('c'),
            'total' => count($tickets),
        ]);
    }
}
```

### 3. Wire the Tool

```php
// app/Tools/ProcessTicketQueueTool.php
final class ProcessTicketQueueTool
{
    public static function make(): Tool
    {
        return new Tool(
            name: 'process_ticket_queue',
            description: 'Process all open tickets in a queue, resolve or escalate each.',
            inputSchema: [
                'type' => 'object',
                'required' => ['queue_id'],
                'properties' => [
                    'queue_id' => ['type' => 'integer', 'description' => 'Queue to process'],
                ],
            ],
            handler: fn(array $input) => (new ProcessTicketQueueWorkflow(
                app()->commands,
                app()->queries,
            ))->execute($input),
            abilities: ['tools:tickets'],
            annotations: ['idempotentHint' => true],
        );
    }
}
```

### 4. Register in Your Provider

```php
// app/Providers/AuxServiceProvider.php
namespace App\Providers;

class AuxServiceProvider extends \Fw\Providers\AuxServiceProvider
{
    protected array $tools = [
        \App\Tools\ProcessTicketQueueTool::class,
    ];
}
```

Add to `config/providers.php`:

```php
App\Providers\AuxServiceProvider::class,
```

### 5. Verify Registration

```bash
php fw aux:list
```

Output:

```
Registered AUX Tools

 Name                   | Description                                              | Abilities
 process_ticket_queue   | Process all open tickets in a queue, resolve or escalate | tools:tickets

Total: 1 tool(s)
```

### 6. Test from CLI

```bash
php fw aux:call process_ticket_queue --input '{"queue_id": 147}'
```

Output:

```json
{
    "completed": [
        {"id": 301, "action": "resolved", "resolution": "refund processed"}
    ],
    "failed": [
        {"id": 302, "error": "requires manager approval"}
    ],
    "pending": [],
    "metadata": {
        "processed_at": "2026-04-16T21:14:38+00:00",
        "total": 2,
        "duration_ms": 234.56
    }
}
```

## Architecture

```
┌─────────────────┐     ┌──────────────┐     ┌──────────────┐
│   AI Agent       │────▶│  Transport   │────▶│ ToolRegistry │
│ (Claude, GPT, …)│     │ stdio or HTTP│     │              │
└─────────────────┘     └──────────────┘     └──────────────┘
                                                    │
                                              ┌─────┴─────┐
                                              │   Tool    │
                                              │  handler  │
                                              └─────┬─────┘
                                              ┌─────┴─────┐
                                              │ Workflow  │
                                              │  Action   │
                                              └───────────┘
```

### Components

| Component | Purpose |
|-----------|---------|
| `Tool` | Value object: name, description, JSON Schema input, handler closure, ability gates, MCP annotations |
| `ToolRegistry` | Registers tools, filters by abilities, validates input, executes handlers, captures duration, dispatches events |
| `WorkflowResult` | Structured outcome: completed[], failed[], pending[], metadata[], duration |
| `WorkflowAction` | Abstract base with `$this->dispatch(Command)` and `$this->query(Query)` |
| `McpProtocol` | Stateless JSON-RPC 2.0 handler (shared by all transports) |
| `McpServer` | stdio transport for `php fw serve:mcp` |
| `McpSseController` | HTTP SSE transport: GET /mcp/sse + POST /mcp/messages |
| `AgentController` | REST-style HTTP: GET /agent/tools, POST /agent/tools/{name} |
| `AgentMiddleware` | Enforces `Accept: application/json`, adds `X-AUX-Version` header |
| `ToolCalled` | Event dispatched before a tool handler executes |
| `ToolCompleted` | Event dispatched after a tool handler completes (includes duration) |

## CLI Commands

### `aux:list` — List registered tools

```bash
php fw aux:list
```

Shows all registered tools with name, description, and abilities. Use this to verify your tool is registered and visible after adding it to your provider.

### `aux:call` — Invoke a tool

```bash
php fw aux:call <tool-name> --input '{"key": "value"}'
```

Executes a tool handler with the given JSON input and prints the `WorkflowResult` as formatted JSON. Debug your workflow without needing Claude Desktop or an MCP client connected.

The `--input` flag defaults to `{}` if omitted:

```bash
php fw aux:call public_status
```

### `aux:schema` — Inspect tool schema

```bash
php fw aux:schema <tool-name>
```

Dumps the tool's full MCP shape as formatted JSON — name, description, inputSchema, and annotations. Use this to verify your JSON Schema is correct before an agent calls it.

```json
{
    "name": "process_ticket_queue",
    "description": "Process all open tickets in a queue, resolve or escalate each.",
    "inputSchema": {
        "type": "object",
        "required": ["queue_id"],
        "properties": {
            "queue_id": {"type": "integer", "description": "Queue to process"}
        }
    },
    "annotations": {
        "idempotentHint": true
    }
}
```

### `serve:mcp` — Start MCP server

```bash
php fw serve:mcp
```

Starts a JSON-RPC 2.0 server on stdin/stdout. See [mcp.md](mcp.md) for client setup.

## Transports

### MCP (stdio) — Claude Desktop, Cursor

For MCP-compatible clients. See [mcp.md](mcp.md) for setup.

```bash
php fw serve:mcp
```

### MCP (HTTP+SSE) — Web-based MCP clients

```
GET  /mcp/sse        → SSE stream (endpoint event + keepalive)
POST /mcp/messages   → JSON-RPC 2.0 handler
```

Protected by `AgentMiddleware` (enforces `Accept: application/json`). Ability gating is inside `ToolRegistry` — tools with `abilities: []` are public.

### HTTP Agent API — Serverless, containers, non-MCP clients

```
GET  /agent/tools           → List available tools (filtered by token abilities)
POST /agent/tools/{name}    → Invoke a tool with JSON body
```

Protected by `ApiAuthMiddleware` (requires `Authorization: Bearer <token>`), `AgentMiddleware`, and `RateLimitMiddleware`.

## Ability Gating

Tools declare required abilities. Callers only see tools they have access to — gated tools are invisible (404), not forbidden (403).

```php
new Tool(
    name: 'admin_report',
    abilities: ['admin'],     // Only visible to tokens with 'admin' ability
    // ...
);

new Tool(
    name: 'public_status',
    abilities: [],            // Visible to everyone, including unauthenticated MCP
    // ...
);
```

Token abilities come from `TokenGuard::tokenAbilities()` (for HTTP) or are passed through the protocol layer (for MCP).

### Reusing Tools at Different Access Levels

Use `withAbilities()` to create a copy of a tool with different abilities:

```php
$baseTool = ProcessTicketQueueTool::make();

$publicTool = $baseTool->withAbilities([]);           // Public access
$adminTool  = $baseTool->withAbilities(['admin']);     // Admin-only access
```

The original tool is unchanged — `Tool` is `final readonly`.

## Tool Annotations

MCP tool annotations hint at behavior without enforcing it. When annotations are non-empty, they appear in the tool's MCP shape (visible in `tools/list` responses and `php fw aux:schema`).

```php
new Tool(
    name: 'delete_user',
    annotations: [
        'destructiveHint' => true,
        'idempotentHint' => false,
        'openWorldHint' => true,
    ],
    // ...
);
```

Tools with no annotations omit the field entirely from the MCP shape.

## WorkflowResult

Three named constructors for different outcomes:

```php
// Everything succeeded
WorkflowResult::success(
    completed: [['id' => 1, 'action' => 'resolved']],
    metadata: ['total_time_ms' => 234],
);

// Mixed results
WorkflowResult::partial(
    completed: [['id' => 1, 'action' => 'resolved']],
    failed: [['id' => 2, 'error' => 'requires approval']],
    pending: [['id' => 3, 'status' => 'awaiting response']],
);

// Total failure
WorkflowResult::error('Database connection failed');
```

### Duration Tracking

`ToolRegistry::call()` automatically captures execution time via `hrtime()` and attaches it to the result. The duration appears in `metadata.duration_ms` when serialized:

```json
{
  "completed": [{"id": 1, "action": "resolved"}],
  "failed": [],
  "pending": [],
  "metadata": {"duration_ms": 234.56}
}
```

No manual instrumentation needed — every tool call gets duration for free.

### Merging Results

When a compound workflow calls sub-workflows, merge their results into a single agent-facing response:

```php
public function execute(array $input): WorkflowResult
{
    $ticketResult = (new ProcessTicketsWorkflow($this->commands, $this->queries))
        ->execute($input);

    $emailResult = (new SendNotificationsWorkflow($this->commands, $this->queries))
        ->execute(['ticket_ids' => array_column($ticketResult->completed, 'id')]);

    return $ticketResult->merge($emailResult);
}
```

`merge()` concatenates completed/failed/pending arrays, merges metadata (later keys win), and propagates `isError` (true if any input result is an error). Accepts variadic arguments:

```php
$combined = $first->merge($second, $third, $fourth);
```

### MCP Content Format

Results serialize to MCP-compliant content:

```json
{
  "content": [{"type": "text", "text": "{\"completed\":[...],\"failed\":[...]}"}],
  "isError": false
}
```

## Observability

### Events

When an `EventDispatcher` is configured on the registry, `ToolRegistry::call()` dispatches two events per invocation:

| Event | When | Properties |
|-------|------|------------|
| `ToolCalled` | Before handler executes | `toolName`, `input`, `occurredAt` |
| `ToolCompleted` | After handler completes | `toolName`, `result` (includes duration), `occurredAt` |

Wire the dispatcher in your provider or bootstrap:

```php
// In a service provider
$registry = $this->container->get(ToolRegistry::class);
$events = $this->container->get(EventDispatcher::class);
$registry->setEventDispatcher($events);
```

Listen for events to log, meter, or alert on agent activity:

```php
$events->listen(ToolCompleted::class, function (ToolCompleted $event) {
    Log::info('Tool completed', [
        'tool' => $event->toolName,
        'duration_ms' => $event->result->duration,
        'is_error' => $event->result->isError,
    ]);
});
```

Events are optional — if no `EventDispatcher` is set, `ToolRegistry` skips dispatch with zero overhead.

## Input Validation

`ToolRegistry` performs lightweight JSON Schema validation before calling the handler:

- **`required`**: Checks that required properties are present
- **Scalar `type`**: Validates `string`, `integer`, `number`, `boolean`, `array`, `object`, `null`

For complex validation, use a `FormRequest` inside your handler:

```php
handler: function (array $input) {
    $validated = StoreTicketRequest::fromArray($input);
    return (new ProcessWorkflow())->execute($validated->toArray());
},
```

## Configuration

`config/aux.php`:

```php
return [
    'mcp_enabled' => Env::bool('AUX_MCP_ENABLED', true),
    'http_agent_enabled' => Env::bool('AUX_HTTP_AGENT_ENABLED', true),
    'sse_heartbeat_seconds' => Env::int('AUX_SSE_HEARTBEAT', 15),
    'agent_rate_limit' => [
        'max_requests' => Env::int('AUX_RATE_LIMIT', 100),
        'window_seconds' => Env::int('AUX_RATE_LIMIT_WINDOW', 60),
    ],
    'mcp_server_name' => Env::string('AUX_SERVER_NAME', 'VibeFW AUX'),
    'mcp_server_version' => Env::string('AUX_SERVER_VERSION', '3.0'),
];
```

| Key | Effect |
|-----|--------|
| `mcp_enabled` | Set `false` to disable `/mcp/*` routes entirely |
| `http_agent_enabled` | Set `false` to disable `/agent/*` routes entirely |
| `sse_heartbeat_seconds` | Keepalive ping interval for SSE connections |
| `agent_rate_limit` | Rate limiting for `/agent/*` routes (via `RateLimitMiddleware`) |
| `mcp_server_name` | Server name in MCP `initialize` response |
| `mcp_server_version` | Server version in MCP `initialize` response |

## Testing Your Tools

```php
// Unit test — tool handler directly
$tool = ProcessTicketQueueTool::make();
$result = ($tool->handler)(['queue_id' => 147]);
$this->assertFalse($result->isError);
$this->assertNotEmpty($result->completed);

// Unit test — via registry (includes validation + duration tracking)
$registry = new ToolRegistry();
$registry->register($tool);
$result = $registry->call('process_ticket_queue', ['queue_id' => 147]);
$this->assertTrue($result->isOk());
$workflowResult = $result->unwrapOr(null);
$this->assertNotNull($workflowResult->duration);

// Unit test — via MCP protocol (full JSON-RPC round-trip)
$protocol = new McpProtocol($registry);
$json = $protocol->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"process_ticket_queue","arguments":{"queue_id":147}}}');
$decoded = json_decode($json, true);
$this->assertArrayHasKey('content', $decoded['result']);

// Integration test — verify events dispatch
$dispatched = [];
$events = new EventDispatcher();
$events->listen(ToolCompleted::class, function ($e) use (&$dispatched) {
    $dispatched[] = $e;
});
$registry->setEventDispatcher($events);
$registry->call('process_ticket_queue', ['queue_id' => 147]);
$this->assertCount(1, $dispatched);
```

## Complete File Layout

```
src/Aux/
├── Tool.php                           # Value object: name, schema, handler, abilities, annotations
├── ToolRegistry.php                   # Register, validate, call, filter, events, duration
├── WorkflowResult.php                 # Structured outcome with merge() and withDuration()
├── WorkflowAction.php                 # Abstract base wrapping CommandBus/QueryBus
├── Events/
│   ├── ToolCalled.php                 # Dispatched before handler executes
│   └── ToolCompleted.php             # Dispatched after handler completes
├── Exceptions/
│   ├── ToolNotFoundException.php
│   └── ToolValidationException.php
├── Http/
│   ├── AgentController.php           # GET /agent/tools, POST /agent/tools/{name}
│   ├── AgentMiddleware.php           # Accept enforcement + X-AUX-Version header
│   └── McpSseController.php         # GET /mcp/sse + POST /mcp/messages
└── Mcp/
    ├── McpProtocol.php               # Stateless JSON-RPC 2.0 handler
    ├── McpServer.php                 # stdio transport
    ├── McpMessage.php                # JSON-RPC message value object
    └── McpError.php                  # JSON-RPC error codes

src/Providers/
└── AuxServiceProvider.php            # Wires singletons, registers routes

src/Console/Commands/
├── AuxListCommand.php                # php fw aux:list
├── AuxCallCommand.php                # php fw aux:call
├── AuxSchemaCommand.php              # php fw aux:schema
└── ServeMcpCommand.php               # php fw serve:mcp

config/
└── aux.php                            # Feature flags, rate limits, server identity
```

## Design Principles

1. **One tool per workflow, not per endpoint.** If an agent needs to chain your tools, your tools are too granular.
2. **Structured results.** Return completed/failed/pending arrays so agents can reason about outcomes without parsing prose.
3. **Invisible gating.** Unauthorized callers don't see gated tools at all — no information leakage.
4. **Transport-agnostic protocol.** `McpProtocol` is pure logic (no I/O). Both stdio and HTTP reuse it.
5. **Agents are users.** Design tool descriptions and schemas for AI comprehension, not human documentation.
6. **Observable by default.** Duration tracking and event dispatch are automatic — no manual instrumentation.

## See Also

- [mcp.md](mcp.md) — MCP protocol details, client setup, error codes
- [authentication.md](authentication.md) — API token auth for /agent/* endpoints
- [cli.md](cli.md) — Full CLI reference including aux:* commands
