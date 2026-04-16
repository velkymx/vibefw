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
| `Tool` | Value object: name, description, JSON Schema input, handler closure, ability gates |
| `ToolRegistry` | Registers tools, filters by abilities, validates input, executes handlers |
| `WorkflowResult` | Structured outcome: completed[], failed[], pending[], metadata[] |
| `WorkflowAction` | Abstract base with `$this->dispatch(Command)` and `$this->query(Query)` |
| `McpProtocol` | Stateless JSON-RPC 2.0 handler (shared by all transports) |
| `McpServer` | stdio transport for `php fw serve:mcp` |
| `McpSseController` | HTTP SSE transport: GET /mcp/sse + POST /mcp/messages |
| `AgentController` | REST-style HTTP: GET /agent/tools, POST /agent/tools/{name} |
| `AgentMiddleware` | Enforces Accept: application/json, adds X-AUX-Version header |

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

Unauthenticated at route level. Tools with `abilities: []` are public; ability-gated tools are filtered by `ToolRegistry`.

### HTTP Agent API — Serverless, containers, non-MCP clients

```
GET  /agent/tools           → List available tools (filtered by token abilities)
POST /agent/tools/{name}    → Invoke a tool with JSON body
```

Requires API token auth via `Authorization: Bearer <token>`. Protected by `ApiAuthMiddleware` + `AgentMiddleware`.

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

## WorkflowResult

Three constructors for different outcomes:

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

Results serialize to MCP-compliant content format:

```json
{
  "content": [{"type": "text", "text": "{\"completed\":[...],\"failed\":[...]}"}],
  "isError": false
}
```

## Tool Annotations

MCP tool annotations hint at behavior without enforcing it:

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

## Design Principles

1. **One tool per workflow, not per endpoint.** If an agent needs to chain your tools, your tools are too granular.
2. **Structured results.** Return completed/failed/pending arrays so agents can reason about outcomes.
3. **Invisible gating.** Unauthorized callers don't see gated tools at all — no information leakage.
4. **Transport-agnostic protocol.** `McpProtocol` is pure logic (no I/O). Both stdio and HTTP reuse it.
5. **Agents are users.** Design tool descriptions and schemas for AI comprehension, not human documentation.
