<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxSchemaCommand extends Command
{
    protected string $name = 'aux:schema';

    protected string $description = 'Show the MCP shape for an AUX tool';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'Tool name to inspect', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');

        $registry = HttpApplication::getInstance()->getContainer()->get(ToolRegistry::class);
        $tool = $registry->get($name);

        return $tool->match(
            some: function ($tool) {
                $shape = $tool->toMcpShape();
                $this->line(json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return 0;
            },
            none: function () use ($name) {
                $this->error("Tool '{$name}' not found.");
                return 1;
            },
        );
    }
}
