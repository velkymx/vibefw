<?php

declare(strict_types=1);

use Fw\Core\Env;

return [
    'mcp_enabled' => Env::bool('AUX_MCP_ENABLED', true),
    'http_agent_enabled' => Env::bool('AUX_HTTP_AGENT_ENABLED', true),
    'sse_heartbeat_seconds' => Env::int('AUX_SSE_HEARTBEAT', 15),
    'agent_rate_limit' => [
        'max_requests' => Env::int('AUX_RATE_LIMIT', 100),
        'window_seconds' => Env::int('AUX_RATE_LIMIT_WINDOW', 60),
    ],
    'mcp_server_name' => Env::string('AUX_SERVER_NAME', 'myapp'),
    'mcp_server_version' => Env::string('AUX_SERVER_VERSION', '1.0.0'),
];
