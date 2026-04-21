<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Core\Router;
use Fw\Core\View;
use Fw\Security\Csrf;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ViewReservedNamesTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/fw_view_reserved_' . uniqid();
        mkdir($this->viewDir, 0o755, true);
        file_put_contents($this->viewDir . '/probe.php', '<?php echo "ok";');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewDir . '/*.php') as $f) {
            unlink($f);
        }
        rmdir($this->viewDir);
    }

    private function makeView(): View
    {
        return new View(
            $this->viewDir,
            new Cache(new MemoryCache()),
            new Router(),
            new Csrf(fn () => null),
        );
    }

    public static function newlyReservedNames(): array
    {
        return [
            'auth' => ['auth'],
            'user' => ['user'],
            'errors' => ['errors'],
        ];
    }

    #[Test]
    #[DataProvider('newlyReservedNames')]
    public function renderRejectsNewlyReservedName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("reserved variable name: {$name}");
        $this->makeView()->render('probe', [$name => 'value']);
    }

    #[Test]
    public function unrelatedKeysStillAllowed(): void
    {
        $output = $this->makeView()->render('probe', ['post' => 'x', 'authorName' => 'y']);
        $this->assertSame('ok', $output);
    }
}
