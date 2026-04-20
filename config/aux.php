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
    'list_routes_enabled' => Env::bool('AUX_LIST_ROUTES_ENABLED', true),
    'mcp_server_name' => Env::string('AUX_SERVER_NAME', 'VibeFW AUX'),
    'mcp_server_version' => Env::string('AUX_SERVER_VERSION', '3.0'),
    'budget_multiplier' => (float) Env::string('AUX_BUDGET_MULTIPLIER', '2.0'),
    'notification_channels' => [
        'log' => [
            'driver' => 'log',
        ],
        // 'mail' => [
        //     'driver' => 'mail',
        //     'from'   => Env::string('AUX_MAIL_FROM', 'agent@example.com'),
        // ],
        // 'webhook' => [
        //     'driver'  => 'webhook',
        //     'url'     => Env::string('AUX_WEBHOOK_URL', ''),
        //     'timeout' => Env::int('AUX_WEBHOOK_TIMEOUT', 5),
        // ],
    ],
];
