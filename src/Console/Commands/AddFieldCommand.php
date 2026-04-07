<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Support\Str;

final class AddFieldCommand extends Command
{
    protected string $name = 'add:field';

    protected string $description = 'Add a field to an existing resource (migration + model + schema)';

    private const array VALID_TYPES = [
        'string', 'text', 'integer', 'boolean',
        'timestamp', 'date', 'decimal', 'foreignId', 'json',
    ];

    private const array TYPE_CASTS = [
        'integer' => 'int',
        'boolean' => 'bool',
        'timestamp' => 'datetime',
        'date' => 'datetime',
        'decimal' => 'float',
        'json' => 'array',
        'foreignId' => 'int',
    ];

    private const array TYPE_MIGRATION_MAP = [
        'string' => 'string',
        'text' => 'text',
        'integer' => 'integer',
        'boolean' => 'boolean',
        'timestamp' => 'timestamp',
        'date' => 'date',
        'decimal' => 'decimal',
        'foreignId' => 'foreignId',
        'json' => 'json',
    ];

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('model', 'The model name (PascalCase)', true);
        $this->addArgument('field', 'The field name', true);
        $this->addArgument('type', 'The field type', true);
        $this->addOption('nullable', 'Make the field nullable', false);
        $this->addOption('unique', 'Add a unique constraint', false);
        $this->addOption('constrained', 'Add foreign key constraint (foreignId only)', false);
        $this->addOption('default', 'Default value for the field', null);
        $this->addOption('index', 'Add an index', false);
    }

    public function handle(): int
    {
        $modelName = $this->argument('model');
        $fieldName = $this->argument('field');
        $type = $this->argument('type');

        if ($modelName === null || $fieldName === null || $type === null) {
            $this->error('Usage: php fw add:field <Model> <field> <type> [--nullable] [--unique] [--constrained] [--default=value]');
            return 1;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            $validList = implode(', ', self::VALID_TYPES);
            $this->error("Invalid field type: {$type}. Valid types: {$validList}");
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $modelPath = $basePath . '/app/Models/' . $modelName . '.php';

        if (!file_exists($modelPath)) {
            $this->error("Model not found: app/Models/{$modelName}.php. Run: php fw make:model {$modelName}");
            return 1;
        }

        $tableName = Str::snake(Str::plural($modelName));

        $this->createMigration($basePath, $tableName, $fieldName, $type);
        $this->updateModel($modelPath, $fieldName, $type);
        $this->updateSchemaJson($basePath, $modelName, $fieldName, $type);

        return 0;
    }

    private function createMigration(string $basePath, string $tableName, string $fieldName, string $type): void
    {
        $migrationsDir = $basePath . '/database/migrations';
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0o755, true);
        }

        $files = glob($migrationsDir . '/*.php') ?: [];
        $maxNumber = 0;
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $matches)) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }
        $nextNumber = str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);

        $migrationName = "add_{$fieldName}_to_{$tableName}_table";
        $migrationPath = "{$migrationsDir}/{$nextNumber}_{$migrationName}.php";

        $columnLine = $this->buildColumnLine($fieldName, $type);

        $content = <<<PHP
<?php

declare(strict_types=1);

use Fw\Database\Migration\Migration;
use Fw\Database\Migration\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        \$this->table('{$tableName}', function (Blueprint \$table): void {
            {$columnLine}
        });
    }

    public function down(): void
    {
        \$this->table('{$tableName}', function (Blueprint \$table): void {
            \$table->dropColumn('{$fieldName}');
        });
    }
};
PHP;

        file_put_contents($migrationPath, $content . "\n");
        $this->success("Migration created: database/migrations/{$nextNumber}_{$migrationName}.php");
    }

    private function buildColumnLine(string $fieldName, string $type): string
    {
        $migType = self::TYPE_MIGRATION_MAP[$type];
        $parts = [];

        if ($migType === 'foreignId') {
            $parts[] = "\$table->foreignId('{$fieldName}')";
        } else {
            $parts[] = "\$table->{$migType}('{$fieldName}')";
        }

        if ($this->hasOption('nullable')) {
            $parts[] = '->nullable()';
        }

        if ($this->hasOption('unique')) {
            $parts[] = '->unique()';
        }

        $default = $this->option('default');
        if ($default !== null && $default !== false) {
            $parts[] = "->default({$default})";
        }

        if ($this->hasOption('index')) {
            $parts[] = '->index()';
        }

        if ($migType === 'foreignId' && $this->hasOption('constrained')) {
            $parts[] = '->constrained()';
        }

        return implode('', $parts) . ';';
    }

    private function updateModel(string $modelPath, string $fieldName, string $type): void
    {
        $content = file_get_contents($modelPath);

        // Add to $fillable
        if (preg_match('/\$fillable\s*=\s*\[([^\]]*)\]/s', $content, $match)) {
            $existing = $match[1];
            if (!str_contains($existing, "'{$fieldName}'")) {
                $trimmed = rtrim($existing);
                // Add the new field after the last entry
                if (trim($trimmed) === '') {
                    $newFillable = "\n        '{$fieldName}',\n    ";
                } else {
                    $newFillable = $trimmed . "\n        '{$fieldName}',\n    ";
                }
                $content = str_replace($match[0], '$fillable = [' . $newFillable . ']', $content);
            }
        }

        // Add to $casts if needed
        $castType = self::TYPE_CASTS[$type] ?? null;
        if ($castType !== null && preg_match('/\$casts\s*=\s*\[([^\]]*)\]/s', $content, $match)) {
            $existing = $match[1];
            if (!str_contains($existing, "'{$fieldName}'")) {
                $trimmed = rtrim($existing);
                if (trim($trimmed) === '') {
                    $newCasts = "\n        '{$fieldName}' => '{$castType}',\n    ";
                } else {
                    $newCasts = $trimmed . "\n        '{$fieldName}' => '{$castType}',\n    ";
                }
                $content = str_replace($match[0], '$casts = [' . $newCasts . ']', $content);
            }
        }

        file_put_contents($modelPath, $content);
        $this->success("Model updated: {$fieldName} added to \$fillable" . ($castType ? " and \$casts" : ''));
    }

    private function updateSchemaJson(string $basePath, string $modelName, string $fieldName, string $type): void
    {
        $schemaPath = $basePath . '/app/Schemas/' . $modelName . '.json';

        if (!file_exists($schemaPath)) {
            return;
        }

        $data = json_decode(file_get_contents($schemaPath), true);
        if (!is_array($data) || !isset($data['fields'])) {
            return;
        }

        $fieldDef = ['type' => $type];

        if ($this->hasOption('nullable')) {
            $fieldDef['nullable'] = true;
        }
        if ($this->hasOption('unique')) {
            $fieldDef['unique'] = true;
        }
        if ($this->hasOption('constrained')) {
            $fieldDef['constrained'] = true;
        }
        if ($this->hasOption('index')) {
            $fieldDef['index'] = true;
        }

        $default = $this->option('default');
        if ($default !== null && $default !== false) {
            $fieldDef['default'] = $default;
        }

        $data['fields'][$fieldName] = $fieldDef;

        file_put_contents($schemaPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->success("Schema updated: app/Schemas/{$modelName}.json");
    }
}
