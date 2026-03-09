<?php

declare(strict_types=1);

namespace Fw\Console;

use Throwable;

/**
 * Base class for console commands.
 */
abstract class Command
{
    protected string $name = '';

    protected string $description = '';

    /** @var array<string, array{description: string, required: bool}> */
    protected array $arguments = [];

    /** @var array<string, array{description: string, default: mixed, shortcut?: string}> */
    protected array $options = [];

    protected Application $app;

    protected Input $input;

    protected Output $output;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Execute the command.
     *
     * @return int Exit code (0 = success)
     */
    abstract public function handle(): int;

    /**
     * Configure the command (called before handle).
     */
    public function configure(): void
    {
        // Override in subclass to add arguments/options
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, array{description: string, required: bool}>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, array{description: string, default: mixed, shortcut?: string}>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function setInput(Input $input): void
    {
        $this->input = $input;
    }

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }

    /**
     * Call another console command.
     */
    protected function call(string $name, array $args = []): int
    {
        // Add command name to args for Input
        array_unshift($args, $name);

        $app = $this->getApplication();
        return $app->run($args);
    }

    /**
     * Get the console application instance.
     */
    protected function getApplication(): Application
    {
        // This requires the application to be passed to the command or accessible.
        // I will update the constructor of Command to accept Application.
        return $this->app;
    }

    protected function argument(string $name): ?string
    {
        return $this->input->argument($name);
    }

    protected function option(string $name): mixed
    {
        return $this->input->option($name);
    }

    protected function hasOption(string $name): bool
    {
        return $this->input->hasOption($name);
    }

    protected function line(string $text): void
    {
        $this->output->line($text);
    }

    protected function info(string $text): void
    {
        $this->output->info($text);
    }

    protected function success(string $text): void
    {
        $this->output->success($text);
    }

    protected function warning(string $text): void
    {
        $this->output->warning($text);
    }

    protected function error(string $text): void
    {
        $this->output->error($text);
    }

    protected function comment(string $text): void
    {
        $this->output->comment($text);
    }

    protected function newLine(int $count = 1): void
    {
        $this->output->newLine($count);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }

    /**
     * Run a task with visual feedback.
     */
    protected function task(string $description, callable $task): void
    {
        $this->output->write("  $description: ");

        try {
            $result = $task();
            $this->output->write($this->output->color('DONE', 'green'));
            if (is_string($result)) {
                $this->output->write(" <comment>($result)</comment>");
            }
            $this->output->newLine();
        } catch (Throwable $e) {
            $this->output->write($this->output->color('FAILED', 'red'));
            $this->output->newLine();
            throw $e;
        }
    }

    /**
     * Ask a question and return the answer.
     */
    protected function ask(string $question, ?string $default = null): string
    {
        $prompt = $question;
        if ($default !== null) {
            $prompt .= ' [' . $default . ']';
        }
        $prompt .= ': ';

        $this->output->write($this->output->color($prompt, 'green'));

        $answer = trim((string) fgets(STDIN));

        return $answer !== '' ? $answer : ($default ?? '');
    }

    /**
     * Ask for confirmation (y/n).
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        $defaultText = $default ? 'Y/n' : 'y/N';
        $prompt = $question . ' [' . $defaultText . ']: ';

        $this->output->write($this->output->color($prompt, 'green'));

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }

    /**
     * Present a choice menu.
     *
     * @param array<string|int, string> $choices
     */
    protected function choice(string $question, array $choices, mixed $default = null): string
    {
        $this->line($question);
        $keys = array_keys($choices);

        foreach ($choices as $key => $label) {
            $marker = (string) $default === (string) $key ? '*' : ' ';
            $this->line("  [$marker] [$key] $label");
        }

        $defaultText = $default !== null ? (string) $default : '';
        $answer = $this->ask('Enter choice', $defaultText);

        if (isset($choices[$answer])) {
            return (string) $answer;
        }

        return (string) ($default ?? $keys[0]);
    }

    /**
     * Define an argument.
     */
    protected function addArgument(string $name, string $description, bool $required = true): static
    {
        $this->arguments[$name] = [
            'description' => $description,
            'required' => $required,
        ];
        return $this;
    }

    /**
     * Define an option.
     */
    protected function addOption(
        string $name,
        string $description,
        mixed $default = null,
        ?string $shortcut = null,
    ): static {
        $this->options[$name] = [
            'description' => $description,
            'default' => $default,
        ];
        if ($shortcut !== null) {
            $this->options[$name]['shortcut'] = $shortcut;
        }
        return $this;
    }
}
