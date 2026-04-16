<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxCallCommand extends Command
{
    protected string $name = 'aux:call';

    protected string $description = 'Invoke an AUX tool from the command line';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'Tool name to invoke', true);
        $this->addOption('input', 'JSON input for the tool', '{}');
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        $inputJson = $this->option('input') ?? '{}';
        $input = json_decode($inputJson, true);

        if ($input === null) {
            $this->error('Invalid JSON input.');
            return 1;
        }

        $registry = HttpApplication::getInstance()->getContainer()->get(ToolRegistry::class);
        $result = $registry->call($name, $input);

        return $result->match(
            ok: function ($workflowResult) {
                $output = json_encode($workflowResult->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->line($output);
                return $workflowResult->isError ? 1 : 0;
            },
            err: function ($error) {
                $this->error($error->getMessage());
                return 1;
            },
        );
    }
}
