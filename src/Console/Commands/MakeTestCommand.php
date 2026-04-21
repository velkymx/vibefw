<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Support\Str;

final class MakeTestCommand extends Command
{
    protected string $name = 'make:test';

    protected string $description = 'Generate unit and feature tests for a model';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('model', 'The model name (PascalCase)', true);
    }

    public function handle(): int
    {
        $model = $this->argument('model');
        if ($model === null) {
            $this->error('Usage: php fw make:test <Model>');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $modelPath = $basePath . '/app/Models/' . $model . '.php';

        if (!file_exists($modelPath)) {
            $this->error("Model not found: app/Models/{$model}.php. Run: php fw make:model {$model}");
            return 1;
        }

        $modelContent = file_get_contents($modelPath);
        $fillable = $this->extractArrayProperty($modelContent, 'fillable');
        $casts = $this->extractAssocProperty($modelContent, 'casts');
        $table = $this->extractStringProperty($modelContent, 'table');

        $this->generateUnitTest($basePath, $model, $fillable, $casts, $table);
        $this->generateFeatureTest($basePath, $model, $table);

        return 0;
    }

    private function generateUnitTest(string $basePath, string $model, array $fillable, array $casts, string $table): void
    {
        $path = $basePath . '/tests/Unit/' . $model . 'ModelTest.php';
        $this->ensureDir(dirname($path));

        $fillableChecks = '';
        foreach ($fillable as $field) {
            $fillableChecks .= "        \$this->assertContains('{$field}', {$model}::\$fillable);\n";
        }

        $castsChecks = '';
        foreach ($casts as $field => $castType) {
            $castsChecks .= "        \$this->assertSame('{$castType}', {$model}::\$casts['{$field}']);\n";
        }

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Tests\Unit;

            use App\Models\\{$model};
            use PHPUnit\Framework\Attributes\Test;
            use Fw\Tests\TestCase;

            final class {$model}ModelTest extends TestCase
            {
                #[Test]
                public function modelHasCorrectTable(): void
                {
                    \$this->assertSame('{$table}', {$model}::\$table);
                }

                #[Test]
                public function modelHasCorrectFillable(): void
                {
            {$fillableChecks}    }

                #[Test]
                public function modelHasCorrectCasts(): void
                {
            {$castsChecks}    }
            }
            PHP;

        file_put_contents($path, $content . "\n");
        $this->success("Unit test created: tests/Unit/{$model}ModelTest.php");
    }

    private function generateFeatureTest(string $basePath, string $model, string $table): void
    {
        $path = $basePath . '/tests/Feature/' . $model . 'CrudTest.php';
        $this->ensureDir(dirname($path));

        $routePrefix = Str::kebab(Str::plural($model));
        $modelVar = Str::camel($model);

        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Tests\Feature;

            use App\Models\\{$model};
            use Fw\Tests\TestCase;
            use PHPUnit\Framework\Attributes\Test;

            final class {$model}CrudTest extends TestCase
            {
                protected function setUp(): void
                {
                    parent::setUp();
                    \$this->createDatabase();
                    \$this->runMigrations();
                }

                #[Test]
                public function indexReturnsSuccessful(): void
                {
                    \$response = \$this->get('/{$routePrefix}');

                    \$this->assertSame(200, \$response->getStatusCode());
                }

                #[Test]
                public function storeCreatesNew{$model}(): void
                {
                    \$response = \$this->post('/{$routePrefix}', [
                        // Add required fields here
                    ]);

                    \$this->assertContains(\$response->getStatusCode(), [201, 302]);
                }

                #[Test]
                public function showReturns{$model}(): void
                {
                    // Create a {$modelVar} first, then fetch it
                    \$response = \$this->get('/{$routePrefix}/1');

                    \$this->assertContains(\$response->getStatusCode(), [200, 404]);
                }

                #[Test]
                public function updateModifies{$model}(): void
                {
                    \$response = \$this->put('/{$routePrefix}/1', [
                        // Add fields to update here
                    ]);

                    \$this->assertContains(\$response->getStatusCode(), [200, 302, 404]);
                }

                #[Test]
                public function destroyDeletes{$model}(): void
                {
                    \$response = \$this->delete('/{$routePrefix}/1');

                    \$this->assertContains(\$response->getStatusCode(), [200, 204, 302]);
                }
            }
            PHP;

        file_put_contents($path, $content . "\n");
        $this->success("Feature test created: tests/Feature/{$model}CrudTest.php");
    }

    private function extractArrayProperty(string $content, string $name): array
    {
        if (preg_match('/\$' . $name . '\s*=\s*\[([^\]]*)\]/s', $content, $match)) {
            preg_match_all("/'([^']+)'/", $match[1], $items);
            return $items[1] ?? [];
        }
        return [];
    }

    private function extractAssocProperty(string $content, string $name): array
    {
        $result = [];
        if (preg_match('/\$' . $name . '\s*=\s*\[([^\]]*)\]/s', $content, $match)) {
            preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $match[1], $items, PREG_SET_ORDER);
            foreach ($items as $item) {
                $result[$item[1]] = $item[2];
            }
        }
        return $result;
    }

    private function extractStringProperty(string $content, string $name): string
    {
        if (preg_match('/\$' . $name . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $match)) {
            return $match[1];
        }
        return '';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }
}
