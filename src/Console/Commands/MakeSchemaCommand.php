<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Support\Str;

final class MakeSchemaCommand extends Command
{
    protected string $name = 'make:schema';

    protected string $description = 'Create a new resource schema JSON file';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The model name (PascalCase)', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Schema name is required. Usage: php fw make:schema Post');
            return 1;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error("Schema name must be PascalCase (e.g., Post, BlogPost). Got: {$name}");
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $schemaDir = $basePath . '/app/Schemas';
        $schemaPath = $schemaDir . '/' . $name . '.json';

        if (file_exists($schemaPath)) {
            $this->error("Schema already exists: {$schemaPath}");
            return 1;
        }

        if (!is_dir($schemaDir)) {
            mkdir($schemaDir, 0o755, true);
        }

        $tableName = Str::snake(Str::plural($name));

        $schema = [
            '$schema' => 'fw://resource-schema',
            'model' => $name,
            'table' => $tableName,
            'api' => false,
            'fields' => [
                'name' => ['type' => 'string', 'length' => 255, 'required' => true],
            ],
        ];

        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($schemaPath, $json . "\n");

        $this->success("Schema created: app/Schemas/{$name}.json");
        $this->info("Edit the fields, then run: php fw make:resource --schema=app/Schemas/{$name}.json");

        return 0;
    }
}
