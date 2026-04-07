<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MakeResourceCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/app/Schemas', 0o755, true);
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/app/Controllers', 0o755, true);
        mkdir($this->tempDir . '/app/Requests', 0o755, true);
        mkdir($this->tempDir . '/app/Views', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);
        mkdir($this->tempDir . '/database/factories', 0o755, true);
        mkdir($this->tempDir . '/stubs', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir);

        $this->writeSchemaFile();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function generatesModelFile(): void
    {
        $exitCode = $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempDir . '/app/Models/Post.php');

        $content = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString("class Post extends Model", $content);
        $this->assertStringContainsString("'title'", $content);
        $this->assertStringContainsString("'content'", $content);
        $this->assertStringContainsString('$fillable', $content);
    }

    #[Test]
    public function generatesModelWithCorrectCasts(): void
    {
        $this->writeSchemaFile([
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
                'views' => ['type' => 'integer'],
                'published' => ['type' => 'boolean'],
                'published_at' => ['type' => 'timestamp'],
                'metadata' => ['type' => 'json'],
            ],
        ]);

        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $content = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString("'views' => 'int'", $content);
        $this->assertStringContainsString("'published' => 'bool'", $content);
        $this->assertStringContainsString("'published_at' => 'datetime'", $content);
        $this->assertStringContainsString("'metadata' => 'array'", $content);
    }

    #[Test]
    public function generatesMigrationFile(): void
    {
        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $migrations = glob($this->tempDir . '/database/migrations/*_create_posts_table.php');
        $this->assertNotEmpty($migrations);

        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('posts', $content);
        $this->assertStringContainsString('string', $content);
        $this->assertStringContainsString('title', $content);
    }

    #[Test]
    public function generatesControllerFile(): void
    {
        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $this->assertFileExists($this->tempDir . '/app/Controllers/PostController.php');

        $content = file_get_contents($this->tempDir . '/app/Controllers/PostController.php');
        $this->assertStringContainsString('class PostController extends Controller', $content);
        $this->assertStringContainsString('function index', $content);
        $this->assertStringContainsString('function store', $content);
        $this->assertStringContainsString('StorePostRequest', $content);
        $this->assertStringContainsString('UpdatePostRequest', $content);
    }

    #[Test]
    public function generatesFormRequestFiles(): void
    {
        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $this->assertFileExists($this->tempDir . '/app/Requests/StorePostRequest.php');
        $this->assertFileExists($this->tempDir . '/app/Requests/UpdatePostRequest.php');

        $storeContent = file_get_contents($this->tempDir . '/app/Requests/StorePostRequest.php');
        $this->assertStringContainsString('class StorePostRequest extends FormRequest', $storeContent);
        $this->assertStringContainsString('function rules', $storeContent);
        $this->assertStringContainsString('Required', $storeContent);
    }

    #[Test]
    public function generatesFactoryFile(): void
    {
        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $this->assertFileExists($this->tempDir . '/database/factories/PostFactory.php');

        $content = file_get_contents($this->tempDir . '/database/factories/PostFactory.php');
        $this->assertStringContainsString('PostFactory', $content);
        $this->assertStringContainsString('definition', $content);
    }

    #[Test]
    public function failsWithInvalidSchema(): void
    {
        file_put_contents(
            $this->tempDir . '/app/Schemas/Bad.json',
            json_encode(['model' => 'Bad'])
        );

        $exitCode = $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Bad.json']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithMissingSchemaFile(): void
    {
        $exitCode = $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Nope.json']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithoutSchemaOption(): void
    {
        $exitCode = $this->console->run(['fw', 'make:resource']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function apiSchemaGeneratesJsonController(): void
    {
        $this->writeSchemaFile([
            'api' => true,
            'fields' => [
                'title' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $this->console->run(['fw', 'make:resource', '--schema=app/Schemas/Post.json']);

        $this->assertFileExists($this->tempDir . '/app/Controllers/PostController.php');

        $content = file_get_contents($this->tempDir . '/app/Controllers/PostController.php');
        $this->assertStringContainsString('json', $content);
        // API controller should not reference views
        $this->assertStringNotContainsString('$this->view', $content);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function writeSchemaFile(array $overrides = []): void
    {
        $schema = array_merge([
            '$schema' => 'fw://resource-schema',
            'model' => 'Post',
            'table' => 'posts',
            'api' => false,
            'fields' => [
                'title' => ['type' => 'string', 'length' => 255, 'required' => true],
                'content' => ['type' => 'text', 'required' => true],
            ],
        ], $overrides);

        file_put_contents(
            $this->tempDir . '/app/Schemas/Post.json',
            json_encode($schema, JSON_PRETTY_PRINT)
        );
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
