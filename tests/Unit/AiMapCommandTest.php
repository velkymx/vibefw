<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\AiMapCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiMapCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_aimap_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/app/Controllers', 0o755, true);
        mkdir($this->tempDir . '/app/Requests', 0o755, true);
        mkdir($this->tempDir . '/config', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function commandIsRegistered(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2));
        $commands = $app->getCommands();

        $found = false;
        foreach ($commands as $command) {
            if ($command->getName() === 'ai:map') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The "ai:map" command should be registered');
    }

    #[Test]
    public function generatesMapFile(): void
    {
        $this->writeFixtures();

        $exitCode = $this->console->run(['fw', 'ai:map']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempDir . '/ai-app-map.md');
    }

    #[Test]
    public function mapContainsModels(): void
    {
        $this->writeFixtures();

        $this->console->run(['fw', 'ai:map']);

        $content = file_get_contents($this->tempDir . '/ai-app-map.md');
        $this->assertStringContainsString('Post', $content);
        $this->assertStringContainsString('Fillable', $content);
    }

    #[Test]
    public function mapContainsRoutes(): void
    {
        $this->writeFixtures();

        $this->console->run(['fw', 'ai:map']);

        $content = file_get_contents($this->tempDir . '/ai-app-map.md');
        $this->assertStringContainsString('Route', $content);
        $this->assertStringContainsString('/posts', $content);
    }

    #[Test]
    public function mapContainsControllers(): void
    {
        $this->writeFixtures();

        $this->console->run(['fw', 'ai:map']);

        $content = file_get_contents($this->tempDir . '/ai-app-map.md');
        $this->assertStringContainsString('Controller', $content);
        $this->assertStringContainsString('PostController', $content);
    }

    #[Test]
    public function mapContainsRelationships(): void
    {
        $this->writeFixtures();

        $this->console->run(['fw', 'ai:map']);

        $content = file_get_contents($this->tempDir . '/ai-app-map.md');
        $this->assertStringContainsString('Relationship', $content);
    }

    private function writeFixtures(): void
    {
        // Model
        file_put_contents($this->tempDir . '/app/Models/Post.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;
        use Fw\Model\Relations\BelongsTo;

        class Post extends Model
        {
            protected static ?string $table = 'posts';
            protected static array $fillable = ['title', 'content', 'user_id'];
            protected static array $casts = ['published_at' => 'datetime'];

            public function author(): BelongsTo
            {
                return $this->belongsTo(User::class, 'user_id');
            }
        }
        PHP);

        // Controller
        file_put_contents($this->tempDir . '/app/Controllers/PostController.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Controllers;

        use Fw\Core\Controller;
        use Fw\Core\Request;
        use Fw\Core\Response;

        class PostController extends Controller
        {
            public function index(Request $request): Response
            {
                return $this->json([]);
            }

            public function store(Request $request): Response
            {
                return $this->json([], 201);
            }
        }
        PHP);

        // Routes
        file_put_contents($this->tempDir . '/config/routes.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Core\Router;

        return function (Router $router): void {
            $router->get('/posts', [App\Controllers\PostController::class, 'index']);
            $router->post('/posts', [App\Controllers\PostController::class, 'store']);
        };
        PHP);

        // FormRequest
        file_put_contents($this->tempDir . '/app/Requests/StorePostRequest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Requests;

        use Fw\Validation\FormRequest;

        class StorePostRequest extends FormRequest
        {
            public string $title;
            public string $content;

            public function rules(): array
            {
                return [
                    'title' => [new \Fw\Validation\Rules\Required],
                ];
            }
        }
        PHP);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
