<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Support\Str;

final class MakeLinkCommand extends Command
{
    protected string $name = 'make:link';

    protected string $description = 'Link two models with a relationship (migration + model methods)';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('parent', 'The parent model name', true);
        $this->addArgument('child', 'The related model name', true);
        $this->addOption('hasMany', 'Create a one-to-many relationship', null);
        $this->addOption('hasOne', 'Create a one-to-one relationship', null);
        $this->addOption('manyToMany', 'Create a many-to-many relationship', null);
    }

    public function handle(): int
    {
        $parent = $this->argument('parent');
        $child = $this->argument('child');

        if ($parent === null || $child === null) {
            $this->error('Usage: php fw make:link <Parent> <Child> --hasMany|--hasOne|--manyToMany');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $parentPath = $basePath . '/app/Models/' . $parent . '.php';
        $childPath = $basePath . '/app/Models/' . $child . '.php';

        if (!file_exists($parentPath)) {
            $this->error("Model not found: app/Models/{$parent}.php");
            return 1;
        }

        if (!file_exists($childPath)) {
            $this->error("Model not found: app/Models/{$child}.php");
            return 1;
        }

        $hasMany = $this->hasOption('hasMany');
        $hasOne = $this->hasOption('hasOne');
        $manyToMany = $this->hasOption('manyToMany');

        if (!$hasMany && !$hasOne && !$manyToMany) {
            $this->error('Specify a relationship type: --hasMany, --hasOne, or --manyToMany');
            return 1;
        }

        if ($manyToMany) {
            $this->createPivotMigration($basePath, $parent, $child);
            $this->addBelongsToManyMethod($parentPath, $parent, $child);
            $this->addBelongsToManyMethod($childPath, $child, $parent);
        } else {
            $this->createForeignKeyMigration($basePath, $parent, $child);

            if ($hasMany) {
                $this->addHasManyMethod($parentPath, $parent, $child);
            } else {
                $this->addHasOneMethod($parentPath, $parent, $child);
            }

            $this->addBelongsToMethod($childPath, $child, $parent);
        }

        return 0;
    }

    private function createForeignKeyMigration(string $basePath, string $parent, string $child): void
    {
        $foreignKey = Str::snake($parent) . '_id';
        $childTable = Str::snake(Str::plural($child));

        $migrationName = "add_{$foreignKey}_to_{$childTable}_table";
        $migrationPath = $this->nextMigrationPath($basePath, $migrationName);

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            use Fw\Database\Migration\Migration;
            use Fw\Database\Migration\Blueprint;

            return new class extends Migration
            {
                public function up(): void
                {
                    \$this->table('{$childTable}', function (Blueprint \$table): void {
                        \$table->foreignId('{$foreignKey}')->constrained()->cascadeOnDelete();
                    });
                }

                public function down(): void
                {
                    \$this->table('{$childTable}', function (Blueprint \$table): void {
                        \$table->dropColumn('{$foreignKey}');
                    });
                }
            };
            PHP;

        file_put_contents($migrationPath, $content . "\n");
        $this->success("Migration created: " . basename($migrationPath));
    }

    private function createPivotMigration(string $basePath, string $parent, string $child): void
    {
        $names = [Str::snake($parent), Str::snake($child)];
        sort($names);
        $pivotTable = implode('_', $names);

        $migrationName = "create_{$pivotTable}_table";
        $migrationPath = $this->nextMigrationPath($basePath, $migrationName);

        $fk1 = $names[0] . '_id';
        $fk2 = $names[1] . '_id';

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            use Fw\Database\Migration\Migration;
            use Fw\Database\Migration\Blueprint;

            return new class extends Migration
            {
                public function up(): void
                {
                    \$this->create('{$pivotTable}', function (Blueprint \$table): void {
                        \$table->foreignId('{$fk1}')->constrained()->cascadeOnDelete();
                        \$table->foreignId('{$fk2}')->constrained()->cascadeOnDelete();
                    });
                }

                public function down(): void
                {
                    \$this->drop('{$pivotTable}');
                }
            };
            PHP;

        file_put_contents($migrationPath, $content . "\n");
        $this->success("Pivot migration created: " . basename($migrationPath));
    }

    private function addHasManyMethod(string $modelPath, string $parent, string $child): void
    {
        $methodName = Str::camel(Str::plural($child));
        $method = <<<PHP

                public function {$methodName}(): \Fw\Model\Relations\HasMany
                {
                    return \$this->hasMany({$child}::class);
                }
            PHP;

        $this->insertMethodIntoModel($modelPath, $method);
        $this->success("{$parent} model updated: added {$methodName}() method");
    }

    private function addHasOneMethod(string $modelPath, string $parent, string $child): void
    {
        $methodName = Str::camel($child);
        $method = <<<PHP

                public function {$methodName}(): \Fw\Model\Relations\HasOne
                {
                    return \$this->hasOne({$child}::class);
                }
            PHP;

        $this->insertMethodIntoModel($modelPath, $method);
        $this->success("{$parent} model updated: added {$methodName}() method");
    }

    private function addBelongsToMethod(string $modelPath, string $child, string $parent): void
    {
        $methodName = Str::camel($parent);
        $method = <<<PHP

                public function {$methodName}(): \Fw\Model\Relations\BelongsTo
                {
                    return \$this->belongsTo({$parent}::class);
                }
            PHP;

        $this->insertMethodIntoModel($modelPath, $method);
        $this->success("{$child} model updated: added {$methodName}() method");
    }

    private function addBelongsToManyMethod(string $modelPath, string $owner, string $related): void
    {
        $methodName = Str::camel(Str::plural($related));
        $method = <<<PHP

                public function {$methodName}(): \Fw\Model\Relations\BelongsToMany
                {
                    return \$this->belongsToMany({$related}::class);
                }
            PHP;

        $this->insertMethodIntoModel($modelPath, $method);
        $this->success("{$owner} model updated: added {$methodName}() method");
    }

    private function insertMethodIntoModel(string $modelPath, string $method): void
    {
        $content = file_get_contents($modelPath);

        // Find the last closing brace of the class
        $lastBrace = strrpos($content, '}');
        if ($lastBrace === false) {
            return;
        }

        $content = substr($content, 0, $lastBrace) . $method . "\n" . substr($content, $lastBrace);
        file_put_contents($modelPath, $content);
    }

    private function nextMigrationPath(string $basePath, string $migrationName): string
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

        return "{$migrationsDir}/{$nextNumber}_{$migrationName}.php";
    }
}
