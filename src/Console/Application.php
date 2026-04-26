<?php

declare(strict_types=1);

namespace Fw\Console;

use Fw\Core\Env;
use Throwable;

/**
 * Console application - registers and runs commands.
 */
final class Application
{
    private const DEFAULT_VERSION = '3.0.0';

    /** @var array<string, Command> */
    private array $commands = [];

    private Output $output;

    private string $basePath;

    private string $appName;

    private string $appVersion;

    public function __construct(string $basePath, ?Output $output = null)
    {
        $this->basePath = $basePath;
        $this->output = $output ?? new Output();

        // Load environment variables
        Env::load($basePath . '/.env');

        // Load app config
        $appConfig = [];
        $configPath = $basePath . '/config/app.php';
        if (file_exists($configPath)) {
            $appConfig = require $configPath;
        }

        $this->appName = $appConfig['name'] ?? 'VibeFw Framework';
        $this->appVersion = $appConfig['version'] ?? self::DEFAULT_VERSION;

        $this->registerBuiltinCommands();
    }

    public function getOutput(): Output
    {
        return $this->output;
    }

    /**
     * Register a command.
     */
    public function register(Command $command): void
    {
        $command->configure();
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Get all registered commands.
     *
     * @return array<string, Command>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Run the application.
     *
     * @param array<string> $argv
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $input = new Input($argv);
        $commandName = $input->getCommandName();

        // No command - show help
        if ($commandName === '' || $commandName === 'list') {
            return $this->showHelp();
        }

        // Version flag
        if ($commandName === '--version' || $commandName === '-V') {
            return $this->showVersion();
        }

        // Help flag
        if ($commandName === '--help' || $commandName === '-h' || $commandName === 'help') {
            return $this->showHelp();
        }

        // Find command
        $command = $this->findCommand($commandName);
        if ($command === null) {
            $this->output->error("Command '$commandName' not found.");
            $this->suggestCommand($commandName);
            return 1;
        }

        // Re-parse input with command's argument/option definitions
        $input = new Input($argv, $command->getArguments(), $command->getOptions());

        // Check for --help on specific command
        if ($input->hasOption('help')) {
            return $this->showCommandHelp($command);
        }

        // Execute command
        $command->setInput($input);
        $command->setOutput($this->output);

        try {
            return $command->handle();
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());
            if (getenv('APP_DEBUG') === 'true') {
                $this->output->newLine();
                $this->output->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Find a command by name or alias.
     */
    private function findCommand(string $name): ?Command
    {
        // Exact match
        if (isset($this->commands[$name])) {
            return $this->commands[$name];
        }

        // Namespace shortcut: "m:model" -> "make:model"
        foreach ($this->commands as $fullName => $command) {
            if ($this->matchesShortcut($name, $fullName)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Check if input matches a namespace shortcut.
     */
    private function matchesShortcut(string $input, string $fullName): bool
    {
        if (! str_contains($fullName, ':')) {
            return false;
        }

        [$namespace, $name] = explode(':', $fullName, 2);

        if (! str_contains($input, ':')) {
            return false;
        }

        [$inputNs, $inputName] = explode(':', $input, 2);

        return str_starts_with($namespace, $inputNs) && str_starts_with($name, $inputName);
    }

    /**
     * Suggest similar commands.
     */
    private function suggestCommand(string $input): void
    {
        $suggestions = [];

        foreach (array_keys($this->commands) as $name) {
            $distance = levenshtein($input, $name);
            if ($distance <= 3) {
                $suggestions[$name] = $distance;
            }
        }

        if (! empty($suggestions)) {
            asort($suggestions);
            $this->output->newLine();
            $this->output->line('Did you mean one of these?');
            foreach (array_keys($suggestions) as $suggestion) {
                $this->output->line('  ' . $this->output->color($suggestion, 'green'));
            }
        }
    }

    /**
     * Show the application version.
     */
    private function showVersion(): int
    {
        $this->output->line($this->appName . ' ' . $this->output->color('v' . $this->appVersion, 'green'));
        return 0;
    }

    /**
     * Show the help screen with all commands.
     */
    private function showHelp(): int
    {
        $this->output->line('');
        $this->output->line($this->output->color($this->appName, 'green') . ' ' . $this->output->color('v' . $this->appVersion, 'yellow'));
        $this->output->line('');
        $this->output->line($this->output->color('Usage:', 'yellow'));
        $this->output->line('  command [options] [arguments]');
        $this->output->newLine();
        $this->output->line($this->output->color('Available commands:', 'yellow'));

        // Group commands by namespace
        $groups = [];
        foreach ($this->commands as $name => $command) {
            if (str_contains($name, ':')) {
                [$namespace] = explode(':', $name, 2);
            } else {
                $namespace = '';
            }
            $groups[$namespace][$name] = $command;
        }

        // Sort groups
        ksort($groups);

        // Output each group
        foreach ($groups as $namespace => $commands) {
            if ($namespace !== '') {
                $this->output->line(' ' . $this->output->color($namespace, 'yellow'));
            }

            ksort($commands);
            foreach ($commands as $name => $command) {
                $this->output->line(
                    '  ' . $this->output->color(str_pad($name, 24), 'green') . $command->getDescription()
                );
            }
        }

        $this->output->newLine();
        return 0;
    }

    /**
     * Show help for a specific command.
     */
    private function showCommandHelp(Command $command): int
    {
        $this->output->line('');
        $this->output->line($this->output->color('Description:', 'yellow'));
        $this->output->line('  ' . $command->getDescription());
        $this->output->newLine();

        $this->output->line($this->output->color('Usage:', 'yellow'));
        $usage = '  ' . $command->getName();

        foreach ($command->getOptions() as $name => $def) {
            $usage .= " [--$name]";
        }

        foreach ($command->getArguments() as $name => $def) {
            $usage .= $def['required'] ? " <$name>" : " [$name]";
        }

        $this->output->line($usage);

        // Arguments
        if (! empty($command->getArguments())) {
            $this->output->newLine();
            $this->output->line($this->output->color('Arguments:', 'yellow'));
            foreach ($command->getArguments() as $name => $def) {
                $required = $def['required'] ? ' (required)' : '';
                $this->output->line(
                    '  ' . $this->output->color(str_pad($name, 16), 'green') . $def['description'] . $required
                );
            }
        }

        // Options
        if (! empty($command->getOptions())) {
            $this->output->newLine();
            $this->output->line($this->output->color('Options:', 'yellow'));
            foreach ($command->getOptions() as $name => $def) {
                $shortcut = isset($def['shortcut']) ? '-' . $def['shortcut'] . ', ' : '    ';
                $default = $def['default'] !== null ? ' [default: ' . json_encode($def['default']) . ']' : '';
                $this->output->line(
                    '  ' . $shortcut . $this->output->color(str_pad('--' . $name, 18), 'green') . $def['description'] . $default
                );
            }
        }

        $this->output->newLine();
        return 0;
    }

    /**
     * Register built-in commands.
     */
    private function registerBuiltinCommands(): void
    {
        $commands = [
            // Help
            Commands\HelpCommand::class,

            // Code generators
            Commands\MakeModelCommand::class,
            Commands\MakeControllerCommand::class,
            Commands\MakeMigrationCommand::class,
            Commands\MakeMiddlewareCommand::class,
            Commands\MakeProviderCommand::class,
            Commands\MakeFactoryCommand::class,
            Commands\MakeRequestCommand::class,
            Commands\MakeSeederCommand::class,
            Commands\MakeCommandCommand::class,
            Commands\MakeQueryCommand::class,
            Commands\MakeSchemaCommand::class,
            Commands\MakeResourceCommand::class,
            Commands\AddFieldCommand::class,
            Commands\MakeLinkCommand::class,
            Commands\MakeTestCommand::class,
            Commands\ScaffoldSpaCommand::class,

            // Database
            Commands\MigrateCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\MigrateFreshCommand::class,
            Commands\MigrateCheckCommand::class,
            Commands\DbSeedCommand::class,

            // Queue
            Commands\QueueWorkCommand::class,

            // Utilities
            Commands\RoutesListCommand::class,
            Commands\RouteCacheCommand::class,
            Commands\RouteClearCommand::class,
            Commands\ConfigCacheCommand::class,
            Commands\ConfigClearCommand::class,
            Commands\CacheClearCommand::class,
            Commands\OptimizeCommand::class,
            Commands\OptimizeClearCommand::class,
            Commands\SyncEnvCommand::class,
            Commands\SecurityCheckCommand::class,
            Commands\SetupCommand::class,
            Commands\ServeCommand::class,
            Commands\DevCommand::class,

            // Validation
            Commands\ValidateSecurityCommand::class,
            Commands\ValidateConfigCommand::class,
            Commands\ValidateAllCommand::class,
            Commands\CheckCommand::class,
            Commands\FixCommand::class,

            // AI Context
            Commands\AiMapCommand::class,
            Commands\AiContextCommand::class,
            Commands\AiNextCommand::class,

            // Inspection
            Commands\ModelInspectCommand::class,
            Commands\RouteForCommand::class,
            Commands\DbStatusCommand::class,

            // Testing
            Commands\TestCommand::class,
            Commands\TestForCommand::class,

            // AUX (Agent tools)
            Commands\MakeToolCommand::class,
            Commands\MakeWorkflowCommand::class,
            Commands\AuxListCommand::class,
            Commands\AuxCallCommand::class,
            Commands\AuxSchemaCommand::class,
            Commands\AuxFeaturesCommand::class,
            Commands\AuxNotificationsTestCommand::class,
            Commands\AuxStatsCommand::class,
            Commands\AuxDoctorCommand::class,
            Commands\ServeMcpCommand::class,

            // Debugging
            Commands\ErrorExplainCommand::class,
        ];

        foreach ($commands as $class) {
            if (class_exists($class)) {
                $this->register(new $class($this));
            }
        }
    }
}
