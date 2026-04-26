<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Core\Router;
use Fw\Core\View;
use Fw\Security\Csrf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ViewThisLeakAndOldInputTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/fw_view_m1_' . uniqid();
        mkdir($this->viewDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewDir . '/*.php') as $f) {
            unlink($f);
        }
        if (is_dir($this->viewDir)) {
            rmdir($this->viewDir);
        }
        RequestContext::clear();
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

    #[Test]
    public function thisIsNotAvailableInTemplates(): void
    {
        // Template that tries to use $this
        file_put_contents($this->viewDir . '/leak.php', '<?php echo isset($this) ? "LEAKED" : "SAFE"; ?>');

        $output = $this->makeView()->render('leak');
        $this->assertSame('SAFE', $output);
    }

    #[Test]
    public function templateCannotAccessViewInternals(): void
    {
        // Template that tries to reach the router via $this
        file_put_contents($this->viewDir . '/internals.php', '<?php try { echo $this->router ? "LEAKED" : "NO"; } catch (\Error $e) { echo "BLOCKED"; } ?>');

        $output = $this->makeView()->render('internals');
        $this->assertSame('BLOCKED', $output);
    }

    #[Test]
    public function templateHelpersStillWork(): void
    {
        // Template using $e() helper
        file_put_contents($this->viewDir . '/helpers.php', '<?= $e("<script>") ?>, <?= $strUpper("hello") ?>');

        $output = $this->makeView()->render('helpers');
        $this->assertSame('&lt;script&gt;, HELLO', $output);
    }

    #[Test]
    public function templateDataIsStillAccessible(): void
    {
        file_put_contents($this->viewDir . '/data.php', '<?php echo $title; ?>');

        $output = $this->makeView()->render('data', ['title' => 'My Page']);
        $this->assertSame('My Page', $output);
    }

    #[Test]
    public function oldInputReadsFromRequestContext(): void
    {
        $request = new Request();
        RequestContext::create($request);
        RequestContext::current()->set('_old_input', ['email' => 'old@example.com']);

        file_put_contents($this->viewDir . '/old.php', '<?php echo $old("email", "default"); ?>');

        $output = $this->makeView()->render('old');
        $this->assertSame('old@example.com', $output);
    }

    #[Test]
    public function oldInputReturnsDefaultWhenNoContextAndNoSession(): void
    {
        file_put_contents($this->viewDir . '/old_default.php', '<?php echo $old("email", "default@example.com"); ?>');

        $output = $this->makeView()->render('old_default');
        $this->assertSame('default@example.com', $output);
    }

    #[Test]
    public function oldInputFallsBackToSessionWhenNoContext(): void
    {
        // Start session and set _old_input
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @session_start();
        $_SESSION['_old_input'] = ['name' => 'session-value'];
        session_write_close();

        file_put_contents($this->viewDir . '/old_session.php', '<?php echo $old("name", "default"); ?>');

        // Need a session-active path — start session for the render
        @session_start();
        $output = $this->makeView()->render('old_session');

        // Clean up
        unset($_SESSION['_old_input']);
        session_write_close();

        $this->assertSame('session-value', $output);
    }
}
