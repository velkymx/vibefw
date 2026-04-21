<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Schema\ResourceSchema;
use Fw\Schema\ResourceSchemaValidator;
use Fw\Support\Str;

final class MakeResourceCommand extends Command
{
    private const array TYPE_CASTS = [
        'integer' => 'int',
        'boolean' => 'bool',
        'timestamp' => 'datetime',
        'date' => 'datetime',
        'decimal' => 'float',
        'json' => 'array',
        'foreignId' => 'int',
    ];

    private const array TYPE_FAKER_MAP = [
        'string' => 'fake()->sentence()',
        'text' => 'fake()->paragraphs(3, true)',
        'integer' => 'fake()->numberBetween(1, 1000)',
        'boolean' => 'fake()->boolean()',
        'timestamp' => 'fake()->dateTime()',
        'date' => 'fake()->date()',
        'decimal' => 'fake()->randomFloat(2, 0, 9999)',
        'foreignId' => '1',
        'json' => '[]',
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

    protected string $name = 'make:resource';

    protected string $description = 'Generate a full CRUD resource from a validated schema';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('schema', 'Path to the schema JSON file', null, 's');
    }

    public function handle(): int
    {
        $schemaPath = $this->option('schema');
        if ($schemaPath === null || $schemaPath === '' || $schemaPath === true) {
            $this->error('--schema option is required. Usage: php fw make:resource --schema=app/Schemas/Post.json');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $fullPath = $basePath . '/' . ltrim((string) $schemaPath, '/');

        if (!file_exists($fullPath)) {
            $this->error("Schema file not found: {$schemaPath}");
            return 1;
        }

        $json = file_get_contents($fullPath);
        $result = ResourceSchemaValidator::validateJson($json);

        if ($result->isErr()) {
            $this->error('Schema validation failed: ' . $result->unwrapErr());
            $this->info('Fix ' . $schemaPath . ' and re-run.');
            return 1;
        }

        $schema = $result->unwrapOr(null);

        $this->generateModel($schema, $basePath);
        $this->generateMigration($schema, $basePath);
        $this->generateController($schema, $basePath);
        $this->generateFormRequests($schema, $basePath);
        $this->generateFactory($schema, $basePath);

        if (!$schema->api) {
            $this->generateViews($schema, $basePath);
        }

        $this->printRoutes($schema);

        return 0;
    }

    private function generateModel(ResourceSchema $schema, string $basePath): void
    {
        $path = $basePath . '/app/Models/' . $schema->model . '.php';

        if (file_exists($path)) {
            $this->warning("Model already exists, skipping: app/Models/{$schema->model}.php");
            return;
        }

        $fillable = array_map(fn (string $name) => "        '{$name}'", array_keys($schema->fields));
        $fillableStr = implode(",\n", $fillable);

        $casts = [];
        foreach ($schema->fields as $name => $def) {
            $castType = self::TYPE_CASTS[$def['type']] ?? null;
            if ($castType !== null) {
                $casts[] = "        '{$name}' => '{$castType}'";
            }
        }
        $castsStr = !empty($casts) ? implode(",\n", $casts) . ',' : '';

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Models;

            use Fw\Model\Model;

            class {$schema->model} extends Model
            {
                protected static ?string \$table = '{$schema->table}';

                protected static array \$fillable = [
            {$fillableStr},
                ];

                protected static array \$casts = [
            {$castsStr}
                ];
            }

            PHP;

        // Normalize indentation (we used heredoc with class indentation)
        $content = $this->dedent($content);

        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
        $this->success("Model created: app/Models/{$schema->model}.php");
    }

    private function generateMigration(ResourceSchema $schema, string $basePath): void
    {
        $migrationsDir = $basePath . '/database/migrations';
        $this->ensureDir($migrationsDir);

        $files = glob($migrationsDir . '/*.php') ?: [];
        $maxNumber = 0;
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $matches)) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }
        $nextNumber = str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);

        $migrationName = "create_{$schema->table}_table";
        $migrationPath = "{$migrationsDir}/{$nextNumber}_{$migrationName}.php";

        $columns = [];
        foreach ($schema->fields as $name => $def) {
            $columns[] = $this->buildMigrationColumn($name, $def);
        }
        $columnsStr = implode("\n", $columns);

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            use Fw\Database\Migration\Migration;
            use Fw\Database\Migration\Blueprint;

            return new class extends Migration
            {
                public function up(): void
                {
                    \$this->create('{$schema->table}', function (Blueprint \$table): void {
                        \$table->id();
            {$columnsStr}
                        \$table->timestamps();
                    });
                }

                public function down(): void
                {
                    \$this->drop('{$schema->table}');
                }
            };

            PHP;

        $content = $this->dedent($content);
        file_put_contents($migrationPath, $content);
        $this->success("Migration created: database/migrations/{$nextNumber}_{$migrationName}.php");
    }

    private function buildMigrationColumn(string $name, array $def): string
    {
        $type = self::TYPE_MIGRATION_MAP[$def['type']];
        $parts = [];

        if ($type === 'string' && isset($def['length'])) {
            $parts[] = "\$table->{$type}('{$name}', {$def['length']})";
        } elseif ($type === 'foreignId') {
            $parts[] = "\$table->foreignId('{$name}')";
        } else {
            $parts[] = "\$table->{$type}('{$name}')";
        }

        if (!empty($def['nullable'])) {
            $parts[] = '->nullable()';
        }

        if (!empty($def['unique'])) {
            $parts[] = '->unique()';
        }

        if (array_key_exists('default', $def)) {
            $default = var_export($def['default'], true);
            $parts[] = "->default({$default})";
        }

        if (!empty($def['index'])) {
            $parts[] = '->index()';
        }

        if ($type === 'foreignId' && !empty($def['constrained'])) {
            $parts[] = '->constrained()';
            if (!empty($def['onDelete'])) {
                $parts[] = match ($def['onDelete']) {
                    'cascade' => '->cascadeOnDelete()',
                    default => "->onDelete('{$def['onDelete']}')",
                };
            }
        }

        return '            ' . implode('', $parts) . ';';
    }

    private function generateController(ResourceSchema $schema, string $basePath): void
    {
        $controllerName = $schema->model . 'Controller';
        $path = $basePath . '/app/Controllers/' . $controllerName . '.php';

        if (file_exists($path)) {
            $this->warning("Controller already exists, skipping: app/Controllers/{$controllerName}.php");
            return;
        }

        $modelVar = Str::camel($schema->model);
        $routePrefix = Str::kebab(Str::plural($schema->model));

        if ($schema->api) {
            $content = $this->buildApiController($schema, $controllerName, $modelVar, $routePrefix);
        } else {
            $content = $this->buildWebController($schema, $controllerName, $modelVar, $routePrefix);
        }

        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
        $this->success("Controller created: app/Controllers/{$controllerName}.php");
    }

    private function buildApiController(ResourceSchema $schema, string $controllerName, string $modelVar, string $routePrefix): string
    {
        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use App\Models\\{$schema->model};
            use App\Requests\Store{$schema->model}Request;
            use App\Requests\Update{$schema->model}Request;
            use Fw\Core\Controller;
            use Fw\Core\Request;
            use Fw\Core\Response;

            class {$controllerName} extends Controller
            {
                public function index(Request \$request): Response
                {
                    \${$modelVar}s = {$schema->model}::orderBy('created_at', 'desc')
                        ->paginate(15, (int) \$request->get('page', 1));
                    return \$this->json(\${$modelVar}s);
                }

                public function store(Request \$request): Response
                {
                    \$validated = Store{$schema->model}Request::fromArray(\$request->all());
                    if (\$validated->isErr()) {
                        return \$this->json(['errors' => \$validated->unwrapErr()], 422);
                    }
                    \${$modelVar} = {$schema->model}::create(\$validated->unwrapOr([]));
                    return \$this->json(\${$modelVar}, 201);
                }

                public function show(Request \$request, string \$id): Response
                {
                    return {$schema->model}::find((int) \$id)->match(
                        some: fn(\${$modelVar}) => \$this->json(\${$modelVar}),
                        none: fn() => \$this->json(['error' => '{$schema->model} not found'], 404),
                    );
                }

                public function update(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} === null) {
                        return \$this->json(['error' => '{$schema->model} not found'], 404);
                    }

                    \$validated = Update{$schema->model}Request::fromArray(\$request->all());
                    if (\$validated->isErr()) {
                        return \$this->json(['errors' => \$validated->unwrapErr()], 422);
                    }
                    \${$modelVar}->fill(\$validated->unwrapOr([]))->save()->match(ok: fn () => null, err: fn (\$e) => throw \$e);
                    return \$this->json(\${$modelVar});
                }

                public function destroy(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} !== null) {
                        \${$modelVar}->delete();
                    }
                    return \$this->noContent();
                }
            }

            PHP;

        return $this->dedent($content);
    }

    private function buildWebController(ResourceSchema $schema, string $controllerName, string $modelVar, string $routePrefix): string
    {
        $viewPath = Str::snake(Str::plural($schema->model));

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use App\Models\\{$schema->model};
            use App\Requests\Store{$schema->model}Request;
            use App\Requests\Update{$schema->model}Request;
            use Fw\Core\Controller;
            use Fw\Core\Request;
            use Fw\Core\Response;

            class {$controllerName} extends Controller
            {
                public function index(Request \$request): Response
                {
                    \${$modelVar}s = {$schema->model}::orderBy('created_at', 'desc')
                        ->paginate(15, (int) \$request->get('page', 1));
                    return \$this->view('{$viewPath}.index', ['{$modelVar}s' => \${$modelVar}s]);
                }

                public function create(Request \$request): Response
                {
                    return \$this->view('{$viewPath}.create');
                }

                public function store(Request \$request): Response
                {
                    \$validated = Store{$schema->model}Request::fromArray(\$request->all());
                    if (\$validated->isErr()) {
                        return \$this->view('{$viewPath}.create', ['errors' => \$validated->unwrapErr()]);
                    }
                    \${$modelVar} = {$schema->model}::create(\$validated->unwrapOr([]));
                    return \$this->redirect('/{$routePrefix}/' . \${$modelVar}->id);
                }

                public function show(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} === null) {
                        return \$this->notFound();
                    }
                    return \$this->view('{$viewPath}.show', ['{$modelVar}' => \${$modelVar}]);
                }

                public function edit(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} === null) {
                        return \$this->notFound();
                    }
                    return \$this->view('{$viewPath}.edit', ['{$modelVar}' => \${$modelVar}]);
                }

                public function update(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} === null) {
                        return \$this->notFound();
                    }

                    \$validated = Update{$schema->model}Request::fromArray(\$request->all());
                    if (\$validated->isErr()) {
                        return \$this->view('{$viewPath}.edit', ['{$modelVar}' => \${$modelVar}, 'errors' => \$validated->unwrapErr()]);
                    }
                    \${$modelVar}->fill(\$validated->unwrapOr([]))->save()->match(ok: fn () => null, err: fn (\$e) => throw \$e);
                    return \$this->redirect('/{$routePrefix}/' . \${$modelVar}->id);
                }

                public function destroy(Request \$request, string \$id): Response
                {
                    \${$modelVar} = {$schema->model}::find((int) \$id)->unwrapOr(null);
                    if (\${$modelVar} !== null) {
                        \${$modelVar}->delete();
                    }
                    return \$this->redirect('/{$routePrefix}');
                }
            }

            PHP;

        return $this->dedent($content);
    }

    private function generateFormRequests(ResourceSchema $schema, string $basePath): void
    {
        $this->generateFormRequest($schema, $basePath, 'Store');
        $this->generateFormRequest($schema, $basePath, 'Update');
    }

    private function generateFormRequest(ResourceSchema $schema, string $basePath, string $prefix): void
    {
        $className = "{$prefix}{$schema->model}Request";
        $path = $basePath . '/app/Requests/' . $className . '.php';

        if (file_exists($path)) {
            $this->warning("Request already exists, skipping: app/Requests/{$className}.php");
            return;
        }

        $rules = [];
        $imports = ['use Fw\Validation\FormRequest;'];

        foreach ($schema->fields as $name => $def) {
            $fieldRules = $this->buildFieldRules($name, $def, $schema->table, $imports);
            if (!empty($fieldRules)) {
                $rules[] = "            '{$name}' => [{$fieldRules}],";
            } else {
                $rules[] = "            '{$name}' => [],";
            }
        }

        sort($imports);
        $importsStr = implode("\n", $imports);
        $rulesStr = implode("\n", $rules);

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Requests;

            {$importsStr}

            class {$className} extends FormRequest
            {
                public function rules(): array
                {
                    return [
            {$rulesStr}
                    ];
                }
            }

            PHP;

        $content = $this->dedent($content);
        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
        $this->success("Request created: app/Requests/{$className}.php");
    }

    private function buildFieldRules(string $name, array $def, string $table, array &$imports): string
    {
        $rules = [];

        if (!empty($def['required'])) {
            $rules[] = 'new Required';
            $imports[] = 'use Fw\Validation\Rules\Required;';
        }

        if ($def['type'] === 'string') {
            $length = $def['length'] ?? 255;
            $rules[] = "new MaxLength({$length})";
            $imports[] = 'use Fw\Validation\Rules\MaxLength;';
        }

        if ($def['type'] === 'foreignId') {
            // Derive table from field name convention (e.g., user_id -> users)
            $refTable = Str::plural(str_replace('_id', '', $name));
            $rules[] = "new Exists('{$refTable}', 'id')";
            $imports[] = 'use Fw\Validation\Rules\Exists;';
        }

        if (!empty($def['unique'])) {
            $rules[] = "new Unique('{$table}', '{$name}')";
            $imports[] = 'use Fw\Validation\Rules\Unique;';
        }

        $imports = array_unique($imports);

        return implode(', ', $rules);
    }

    private function generateFactory(ResourceSchema $schema, string $basePath): void
    {
        $factoryName = $schema->model . 'Factory';
        $path = $basePath . '/database/factories/' . $factoryName . '.php';

        if (file_exists($path)) {
            $this->warning("Factory already exists, skipping: database/factories/{$factoryName}.php");
            return;
        }

        $definitions = [];
        foreach ($schema->fields as $name => $def) {
            $faker = self::TYPE_FAKER_MAP[$def['type']] ?? 'null';
            $definitions[] = "            '{$name}' => {$faker},";
        }
        $definitionsStr = implode("\n", $definitions);

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Database\Factories;

            use App\Models\\{$schema->model};
            use Fw\Testing\Factory;

            class {$factoryName} extends Factory
            {
                protected string \$model = {$schema->model}::class;

                public function definition(): array
                {
                    return [
            {$definitionsStr}
                    ];
                }
            }

            PHP;

        $content = $this->dedent($content);
        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
        $this->success("Factory created: database/factories/{$factoryName}.php");
    }

    private function generateViews(ResourceSchema $schema, string $basePath): void
    {
        $viewDir = Str::snake(Str::plural($schema->model));
        $viewBasePath = $basePath . '/app/Views/' . $viewDir;
        $this->ensureDir($viewBasePath);

        $modelVar = Str::camel($schema->model);
        $routePrefix = Str::kebab(Str::plural($schema->model));

        foreach (['index', 'create', 'edit', 'show'] as $view) {
            $viewPath = $viewBasePath . '/' . $view . '.php';
            if (file_exists($viewPath)) {
                continue;
            }

            $content = match ($view) {
                'index' => $this->buildIndexView($schema, $modelVar, $routePrefix),
                'create' => $this->buildFormView($schema, $modelVar, $routePrefix, 'Create'),
                'edit' => $this->buildFormView($schema, $modelVar, $routePrefix, 'Edit'),
                'show' => $this->buildShowView($schema, $modelVar, $routePrefix),
            };

            file_put_contents($viewPath, $content);
        }

        $this->success("Views created: app/Views/{$viewDir}/");
    }

    private function buildIndexView(ResourceSchema $schema, string $modelVar, string $routePrefix): string
    {
        $plural = Str::plural($schema->model);
        $firstField = array_key_first($schema->fields);

        return <<<PHP
            <?php \$this->layout('main'); ?>
            <?php \$title = '{$plural}'; ?>

            <?php \$section('content'); ?>
                <h1>{$plural}</h1>
                <a href="/{$routePrefix}/create">Create {$schema->model}</a>

                <?php foreach (\${$modelVar}s['items'] as \${$modelVar}): ?>
                    <div>
                        <a href="/{$routePrefix}/<?= \${$modelVar}->id ?>"><?= \$e(\${$modelVar}->{$firstField}) ?></a>
                    </div>
                <?php endforeach; ?>
            <?php \$endSection(); ?>

            PHP;
    }

    private function buildFormView(ResourceSchema $schema, string $modelVar, string $routePrefix, string $action): string
    {
        $formFields = [];
        foreach ($schema->fields as $name => $def) {
            $inputType = match ($def['type']) {
                'text' => 'textarea',
                'boolean' => 'checkbox',
                'integer', 'decimal' => 'number',
                'date' => 'date',
                'timestamp' => 'datetime-local',
                default => 'text',
            };

            $label = Str::title(str_replace('_', ' ', $name));
            if ($inputType === 'textarea') {
                $formFields[] = "        <label>{$label}</label>\n        <textarea name=\"{$name}\"><?= \$e(\$old('{$name}')) ?></textarea>";
            } else {
                $formFields[] = "        <label>{$label}</label>\n        <input type=\"{$inputType}\" name=\"{$name}\" value=\"<?= \$e(\$old('{$name}')) ?>\">";
            }
        }
        $fieldsStr = implode("\n\n", $formFields);

        $method = $action === 'Create' ? 'POST' : 'PUT';
        $formAction = $action === 'Create'
            ? "/{$routePrefix}"
            : "/{$routePrefix}/<?= \${$modelVar}->id ?>";

        return <<<PHP
            <?php \$this->layout('main'); ?>
            <?php \$title = '{$action} {$schema->model}'; ?>

            <?php \$section('content'); ?>
                <h1>{$action} {$schema->model}</h1>

                <form method="POST" action="{$formAction}">
                    <?= \$csrf() ?>
            {$fieldsStr}

                    <button type="submit">{$action}</button>
                </form>
            <?php \$endSection(); ?>

            PHP;
    }

    private function buildShowView(ResourceSchema $schema, string $modelVar, string $routePrefix): string
    {
        $fields = [];
        foreach (array_keys($schema->fields) as $name) {
            $label = Str::title(str_replace('_', ' ', $name));
            $fields[] = "        <dt>{$label}</dt>\n        <dd><?= \$e(\${$modelVar}->{$name}) ?></dd>";
        }
        $fieldsStr = implode("\n\n", $fields);

        return <<<PHP
            <?php \$this->layout('main'); ?>
            <?php \$title = '{$schema->model} Details'; ?>

            <?php \$section('content'); ?>
                <h1>{$schema->model} Details</h1>

                <dl>
            {$fieldsStr}
                </dl>

                <a href="/{$routePrefix}/<?= \${$modelVar}->id ?>/edit">Edit</a>
            <?php \$endSection(); ?>

            PHP;
    }

    private function printRoutes(ResourceSchema $schema): void
    {
        $controllerName = $schema->model . 'Controller';
        $routePrefix = Str::kebab(Str::plural($schema->model));

        $this->newLine();
        $this->info('Add these routes to config/routes.php:');
        $this->newLine();
        $this->line("    \$router->get('/{$routePrefix}', [App\\Controllers\\{$controllerName}::class, 'index']);");
        if (!$schema->api) {
            $this->line("    \$router->get('/{$routePrefix}/create', [App\\Controllers\\{$controllerName}::class, 'create']);");
        }
        $this->line("    \$router->post('/{$routePrefix}', [App\\Controllers\\{$controllerName}::class, 'store']);");
        $this->line("    \$router->get('/{$routePrefix}/{id}', [App\\Controllers\\{$controllerName}::class, 'show']);");
        if (!$schema->api) {
            $this->line("    \$router->get('/{$routePrefix}/{id}/edit', [App\\Controllers\\{$controllerName}::class, 'edit']);");
        }
        $this->line("    \$router->put('/{$routePrefix}/{id}', [App\\Controllers\\{$controllerName}::class, 'update']);");
        $this->line("    \$router->delete('/{$routePrefix}/{id}', [App\\Controllers\\{$controllerName}::class, 'destroy']);");
    }

    private function dedent(string $content): string
    {
        $lines = explode("\n", $content);
        // Find minimum indentation (ignoring empty lines)
        $minIndent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $minIndent = min($minIndent, $indent);
        }

        if ($minIndent === 0 || $minIndent === PHP_INT_MAX) {
            return $content;
        }

        return implode("\n", array_map(
            fn (string $line) => trim($line) === '' ? '' : substr($line, $minIndent),
            $lines
        ));
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }
}
