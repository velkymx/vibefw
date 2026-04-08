<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteForCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_routefor_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/config', 0o755, true);

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
            if ($command->getName() === 'route:for') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function filtersRoutesByTopic(): void
    {
        $this->writeRoutes();

        ob_start();
        $exitCode = $this->console->run(['fw', 'route:for', 'post']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/posts', $output);
        $this->assertStringContainsString('PostController', $output);
        $this->assertStringNotContainsString('/users', $output);
    }

    #[Test]
    public function showsHttpMethods(): void
    {
        $this->writeRoutes();

        ob_start();
        $this->console->run(['fw', 'route:for', 'post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('GET', $output);
        $this->assertStringContainsString('POST', $output);
    }

    #[Test]
    public function failsWithoutTopic(): void
    {
        $this->writeRoutes();

        ob_start();
        $exitCode = $this->console->run(['fw', 'route:for']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function showsNoMatchesMessage(): void
    {
        $this->writeRoutes();

        ob_start();
        $exitCode = $this->console->run(['fw', 'route:for', 'nonexistent']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No routes', $output);
    }

    private function writeRoutes(): void
    {
        file_put_contents($this->tempDir . '/config/routes.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Core\Router;

        return function (Router $router): void {
            $router->get('/posts', [App\Controllers\PostController::class, 'index']);
            $router->get('/posts/{id}', [App\Controllers\PostController::class, 'show']);
            $router->post('/posts', [App\Controllers\PostController::class, 'store']);
            $router->get('/users', [App\Controllers\UserController::class, 'index']);
        };
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
