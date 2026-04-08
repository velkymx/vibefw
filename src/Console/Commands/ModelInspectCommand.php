<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Inspect a model's configuration and metadata.
 */
final class ModelInspectCommand extends Command
{
    protected string $name = 'model:inspect';

    protected string $description = 'Show model details: table, fillable, casts, relationships';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'Model name to inspect', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null || $name === '') {
            $this->error('Usage: php fw model:inspect <ModelName>');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $modelFile = $basePath . '/app/Models/' . $name . '.php';

        if (!file_exists($modelFile)) {
            $this->error("Model '{$name}' not found at app/Models/{$name}.php");
            $this->line('Available models:');
            $this->listModels($basePath);
            return 1;
        }

        $content = (string) file_get_contents($modelFile);

        $this->newLine();
        $this->info("Model: {$name}");
        $this->line(str_repeat('─', 40));

        // Table
        $table = $this->extractStringProperty($content, 'table');
        if ($table === '') {
            $table = strtolower($name) . 's';
        }
        $this->line("  Table:    {$table}");

        // File
        $relative = 'app/Models/' . $name . '.php';
        $this->line("  File:     {$relative}");
        $this->newLine();

        // Fillable
        $fillable = $this->extractArrayItems($content, 'fillable');
        $this->line($this->output->color('  Fillable:', 'yellow'));
        if (empty($fillable)) {
            $this->line('    (none)');
        } else {
            foreach ($fillable as $field) {
                $this->line("    - {$field}");
            }
        }
        $this->newLine();

        // Casts
        $casts = $this->extractAssocItems($content, 'casts');
        $this->line($this->output->color('  Casts:', 'yellow'));
        if (empty($casts)) {
            $this->line('    (none)');
        } else {
            foreach ($casts as $field => $type) {
                $this->line("    - {$field} → {$type}");
            }
        }
        $this->newLine();

        // Relationships
        $relationships = $this->extractRelationships($content);
        $this->line($this->output->color('  Relationships:', 'yellow'));
        if (empty($relationships)) {
            $this->line('    (none)');
        } else {
            foreach ($relationships as $rel) {
                $this->line("    - {$rel['method']}() → {$rel['type']}");
            }
        }
        $this->newLine();

        return 0;
    }

    private function listModels(string $basePath): void
    {
        $path = $basePath . '/app/Models';
        if (!is_dir($path)) {
            return;
        }

        $dir = new \DirectoryIterator($path);
        foreach ($dir as $file) {
            if ($file->getExtension() === 'php') {
                $this->line('  - ' . $file->getBasename('.php'));
            }
        }
    }

    private function extractStringProperty(string $content, string $name): string
    {
        if (preg_match('/\$' . preg_quote($name) . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * @return array<string>
     */
    private function extractArrayItems(string $content, string $name): array
    {
        if (!preg_match('/\$' . preg_quote($name) . '\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
            return [];
        }

        $items = [];
        if (preg_match_all("/['\"]([^'\"]+)['\"]/", $m[1], $matches)) {
            $items = $matches[1];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function extractAssocItems(string $content, string $name): array
    {
        if (!preg_match('/\$' . preg_quote($name) . '\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
            return [];
        }

        $items = [];
        if (preg_match_all("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]?([^'\"\\],]+)/", $m[1], $matches)) {
            foreach ($matches[1] as $i => $key) {
                $items[$key] = trim($matches[2][$i]);
            }
        }

        return $items;
    }

    /**
     * @return array<array{method: string, type: string}>
     */
    private function extractRelationships(string $content): array
    {
        $relationships = [];
        $types = ['BelongsTo', 'HasMany', 'HasOne', 'BelongsToMany'];

        foreach ($types as $type) {
            $pattern = '/public\s+function\s+(\w+)\s*\(\s*\)\s*:\s*' . preg_quote($type) . '/';
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $method) {
                    $relationships[] = ['method' => $method, 'type' => $type];
                }
            }
        }

        return $relationships;
    }
}
