<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\Mcp\McpServer;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

/**
 * Start MCP server for AI agent connections.
 */
final class ServeMcpCommand extends Command
{
    protected string $name = 'serve:mcp';

    protected string $description = 'Start MCP server for AI agent connections';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $this->info('Starting MCP server...');
        $this->comment('Ready for JSON-RPC connections via stdin/stdout');

        pcntl_async_signals(true);

        $shutdown = function () {
            $this->info('Shutting down MCP server...');
            exit(0);
        };

        pcntl_signal(SIGINT, $shutdown);
        pcntl_signal(SIGTERM, $shutdown);

        $server = HttpApplication::getInstance()->getContainer()->get(McpServer::class);

        $server->run();

        return 0;
    }
}
