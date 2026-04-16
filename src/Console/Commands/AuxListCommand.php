<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxListCommand extends Command
{
    protected string $name = 'aux:list';

    protected string $description = 'List all registered AUX tools';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $registry = HttpApplication::getInstance()->getContainer()->get(ToolRegistry::class);
        $tools = $registry->all();

        if (empty($tools)) {
            $this->comment('No AUX tools registered.');
            return 0;
        }

        $this->newLine();
        $this->info('Registered AUX Tools');
        $this->newLine();

        $rows = [];
        foreach ($tools as $tool) {
            $abilities = $tool->abilities === [] ? 'public' : implode(', ', $tool->abilities);
            $rows[] = [
                $tool->name,
                $tool->description,
                $abilities,
            ];
        }

        $this->table(['Name', 'Description', 'Abilities'], $rows);
        $this->newLine();
        $this->line('Total: ' . count($tools) . ' tool(s)');
        $this->newLine();

        return 0;
    }
}
