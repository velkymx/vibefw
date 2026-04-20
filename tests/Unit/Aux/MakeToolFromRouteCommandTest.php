<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\MakeToolCommand;
use Fw\Console\Input;
use Fw\Console\Output;
use PHPUnit\Framework\TestCase;

final class MakeToolFromRouteCommandTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/fw_maketool_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot . '/app/Tools', 0o755, true);
        mkdir($this->tempRoot . '/app/Workflows', 0o755, true);
        mkdir($this->tempRoot . '/stubs', 0o755, true);
        mkdir($this->tempRoot . '/config', 0o755, true);

        copy(BASE_PATH . '/stubs/tool.stub', $this->tempRoot . '/stubs/tool.stub');
        copy(BASE_PATH . '/stubs/workflow.stub', $this->tempRoot . '/stubs/workflow.stub');
        copy(BASE_PATH . '/stubs/tool.from-route.stub', $this->tempRoot . '/stubs/tool.from-route.stub');
        copy(BASE_PATH . '/stubs/workflow.from-route.stub', $this->tempRoot . '/stubs/workflow.from-route.stub');

        $controller = FromRouteFixtureController::class;
        file_put_contents(
            $this->tempRoot . '/config/routes.php',
            "<?php\nreturn function (\\Fw\\Core\\Router \$r): void {\n"
            . "    \$r->get('/tickets', [\\{$controller}::class, 'index'], 'tickets.index');\n"
            . "};\n",
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempRoot);
    }

    public function testFromRouteOptionIsConfigured(): void
    {
        $cmd = $this->makeCommand(['process_tickets']);

        $this->assertArrayHasKey('from-route', $cmd->getOptions());
    }

    public function testFromRouteGeneratesToolReferencingRoute(): void
    {
        $cmd = $this->makeCommand(['process_tickets'], ['from-route' => 'tickets.index']);

        $this->assertSame(0, $cmd->handle());

        $tool = file_get_contents($this->tempRoot . '/app/Tools/ProcessTicketsTool.php');
        $this->assertStringContainsString("name: 'process_tickets'", $tool);
        $this->assertStringContainsString('tickets.index', $tool);
        $this->assertStringContainsString('GET /tickets', $tool);
    }

    public function testFromRouteGeneratesWorkflowReferencingController(): void
    {
        $cmd = $this->makeCommand(['process_tickets'], ['from-route' => 'tickets.index']);

        $this->assertSame(0, $cmd->handle());

        $workflow = file_get_contents($this->tempRoot . '/app/Workflows/ProcessTicketsWorkflow.php');
        $this->assertStringContainsString('use ' . FromRouteFixtureController::class . ';', $workflow);
        $this->assertStringContainsString('FromRouteFixtureController', $workflow);
        $this->assertStringContainsString('index', $workflow);
    }

    public function testFromRouteUnknownRouteFails(): void
    {
        $cmd = $this->makeCommand(['noop'], ['from-route' => 'does.not.exist']);

        $this->assertSame(1, $cmd->handle());
        $this->assertFileDoesNotExist($this->tempRoot . '/app/Tools/NoopTool.php');
        $this->assertFileDoesNotExist($this->tempRoot . '/app/Workflows/NoopWorkflow.php');
    }

    public function testFromRouteRequiresControllerStyleHandler(): void
    {
        file_put_contents(
            $this->tempRoot . '/config/routes.php',
            "<?php\nreturn function (\\Fw\\Core\\Router \$r): void {\n"
            . "    \$r->get('/x', ['\\\\NotAClass', 'nope'], 'bad.route');\n"
            . "};\n",
        );

        $cmd = $this->makeCommand(['noop'], ['from-route' => 'bad.route']);

        $this->assertSame(1, $cmd->handle());
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $options
     */
    private function makeCommand(array $args, array $options = []): MakeToolCommand
    {
        $console = new ConsoleApp($this->tempRoot);
        $cmd = new MakeToolCommand($console);
        $cmd->configure();
        $cmd->setOutput(new Output());

        $argv = ['fw', 'make:tool', ...$args];
        foreach ($options as $key => $val) {
            $argv[] = "--{$key}={$val}";
        }
        $cmd->setInput(new Input($argv, $cmd->getArguments(), $cmd->getOptions()));

        return $cmd;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = "$dir/$entry";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

final class FromRouteFixtureController
{
    public function index(): void {}
}
