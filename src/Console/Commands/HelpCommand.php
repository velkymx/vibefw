<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Show help information for a command.
 */
final class HelpCommand extends Command
{
    protected string $name = 'help';

    protected string $description = 'Display help for a command';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('command_name', 'The command name', false);
    }

    public function handle(): int
    {
        $commandName = $this->argument('command_name');

        if ($commandName === null) {
            // Show all commands
            $this->line('');
            $this->info('Available commands:');
            $this->newLine();

            $commands = $this->app->getCommands();
            ksort($commands);

            foreach ($commands as $name => $command) {
                $this->line(
                    '  ' . $this->output->color(str_pad($name, 24), 'green') . $command->getDescription()
                );
            }

            $this->newLine();
            $this->comment('Run "php fw help <command>" for more information about a command.');
            $this->newLine();
            return 0;
        }

        // Show specific command help
        $commands = $this->app->getCommands();
        if (! isset($commands[$commandName])) {
            $this->error("Command '$commandName' not found.");
            return 1;
        }

        $command = $commands[$commandName];

        $this->newLine();
        $this->line($this->output->color('Command: ', 'yellow') . $command->getName());
        $this->line($this->output->color('Description: ', 'yellow') . $command->getDescription());
        $this->newLine();

        $arguments = $command->getArguments();
        if (! empty($arguments)) {
            $this->line($this->output->color('Arguments:', 'yellow'));
            foreach ($arguments as $name => $def) {
                $required = $def['required'] ? ' (required)' : ' (optional)';
                $this->line("  $name - {$def['description']}$required");
            }
            $this->newLine();
        }

        $options = $command->getOptions();
        if (! empty($options)) {
            $this->line($this->output->color('Options:', 'yellow'));
            foreach ($options as $name => $def) {
                $shortcut = isset($def['shortcut']) ? "-{$def['shortcut']}, " : '    ';
                $default = $def['default'] !== null ? " [default: {$def['default']}]" : '';
                $this->line("  {$shortcut}--{$name} - {$def['description']}{$default}");
            }
            $this->newLine();
        }

        return 0;
    }
}
