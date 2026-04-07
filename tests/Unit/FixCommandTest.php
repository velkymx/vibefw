<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\FixCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FixCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_fix_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/app/Controllers', 0o755, true);

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
            if ($command->getName() === 'fix') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The "fix" command should be registered');
    }

    #[Test]
    public function commandHasCorrectNameAndDescription(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2));
        $command = new FixCommand($app);

        $this->assertSame('fix', $command->getName());
    }

    #[Test]
    public function fixesGuardedToFillable(): void
    {
        $modelContent = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;

        class Post extends Model
        {
            protected static array $guarded = ['id'];
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Models/Post.php', $modelContent);

        $exitCode = $this->console->run(['fw', 'fix']);

        $fixed = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringNotContainsString('$guarded', $fixed);
        $this->assertStringContainsString('$fillable', $fixed);
    }

    #[Test]
    public function addsStrictTypesWhenMissing(): void
    {
        $content = <<<'PHP'
        <?php

        namespace App\Models;

        use Fw\Model\Model;

        class Tag extends Model
        {
            protected static array $fillable = ['name'];
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Models/Tag.php', $content);

        $this->console->run(['fw', 'fix']);

        $fixed = file_get_contents($this->tempDir . '/app/Models/Tag.php');
        $this->assertStringContainsString('declare(strict_types=1)', $fixed);
    }

    #[Test]
    public function reportsFixCount(): void
    {
        $content = <<<'PHP'
        <?php

        namespace App\Models;

        use Fw\Model\Model;

        class Item extends Model
        {
            protected static array $guarded = [];
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Models/Item.php', $content);

        ob_start();
        $exitCode = $this->console->run(['fw', 'fix']);
        $output = ob_get_clean();

        $this->assertStringContainsString('fix', strtolower($output));
    }

    #[Test]
    public function noChangesWhenCodeIsClean(): void
    {
        $content = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;

        class Clean extends Model
        {
            protected static array $fillable = ['name'];
        }
        PHP;

        file_put_contents($this->tempDir . '/app/Models/Clean.php', $content);

        ob_start();
        $exitCode = $this->console->run(['fw', 'fix']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
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
