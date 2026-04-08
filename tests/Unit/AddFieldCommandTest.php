<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddFieldCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/app/Requests', 0o755, true);
        mkdir($this->tempDir . '/app/Schemas', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir, Output::createBuffer());

        $this->writeModelFile();
        $this->writeRequestFile('StorePostRequest');
        $this->writeRequestFile('UpdatePostRequest');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function createsMigrationFile(): void
    {
        $exitCode = $this->console->run(['fw', 'add:field', 'Post', 'slug', 'string']);

        $this->assertSame(0, $exitCode);

        $migrations = glob($this->tempDir . '/database/migrations/*_add_slug_to_posts_table.php');
        $this->assertNotEmpty($migrations);

        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('slug', $content);
        $this->assertStringContainsString('string', $content);
    }

    #[Test]
    public function updatesModelFillable(): void
    {
        $this->console->run(['fw', 'add:field', 'Post', 'slug', 'string']);

        $content = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString("'slug'", $content);
    }

    #[Test]
    public function updatesModelCastsForTypedFields(): void
    {
        $this->console->run(['fw', 'add:field', 'Post', 'views', 'integer']);

        $content = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString("'views' => 'int'", $content);
    }

    #[Test]
    public function migrationsSupportsUniqueFlag(): void
    {
        $this->console->run(['fw', 'add:field', 'Post', 'slug', 'string', '--unique']);

        $migrations = glob($this->tempDir . '/database/migrations/*_add_slug_to_posts_table.php');
        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('unique', $content);
    }

    #[Test]
    public function migrationSupportsNullableFlag(): void
    {
        $this->console->run(['fw', 'add:field', 'Post', 'bio', 'text', '--nullable']);

        $migrations = glob($this->tempDir . '/database/migrations/*_add_bio_to_posts_table.php');
        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('nullable', $content);
    }

    #[Test]
    public function updatesSchemaJsonIfExists(): void
    {
        $this->writeSchemaFile();

        $this->console->run(['fw', 'add:field', 'Post', 'slug', 'string', '--unique']);

        $content = file_get_contents($this->tempDir . '/app/Schemas/Post.json');
        $data = json_decode($content, true);
        $this->assertArrayHasKey('slug', $data['fields']);
        $this->assertSame('string', $data['fields']['slug']['type']);
        $this->assertTrue($data['fields']['slug']['unique']);
    }

    #[Test]
    public function failsWithInvalidType(): void
    {
        $exitCode = $this->console->run(['fw', 'add:field', 'Post', 'slug', 'invalid_type']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsIfModelDoesNotExist(): void
    {
        $exitCode = $this->console->run(['fw', 'add:field', 'NonExistent', 'slug', 'string']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithoutRequiredArguments(): void
    {
        $exitCode = $this->console->run(['fw', 'add:field', 'Post']);

        $this->assertSame(1, $exitCode);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function writeModelFile(): void
    {
        $content = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;

        class Post extends Model
        {
            protected static ?string $table = 'posts';

            protected static array $fillable = [
                'title',
                'content',
            ];

            protected static array $casts = [
            ];
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Models/Post.php', $this->dedent($content));
    }

    private function writeRequestFile(string $name): void
    {
        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Requests;

        use Fw\Validation\FormRequest;
        use Fw\Validation\Rules\Required;
        use Fw\Validation\Rules\MaxLength;

        class {$name} extends FormRequest
        {
            public function rules(): array
            {
                return [
                    'title' => [new Required, new MaxLength(255)],
                    'content' => [new Required],
                ];
            }
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Requests/' . $name . '.php', $this->dedent($content));
    }

    private function writeSchemaFile(): void
    {
        $schema = [
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'api' => false,
            'fields' => [
                'title' => ['type' => 'string', 'length' => 255, 'required' => true],
                'content' => ['type' => 'text', 'required' => true],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/app/Schemas/Post.json',
            json_encode($schema, JSON_PRETTY_PRINT)
        );
    }

    private function dedent(string $content): string
    {
        $lines = explode("\n", $content);
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
            fn(string $line) => trim($line) === '' ? '' : substr($line, $minIndent),
            $lines
        ));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
