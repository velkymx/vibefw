<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Http;

use Fw\Aux\AgentAccessible;
use Fw\Aux\Http\AgentAccessibleScanner;
use Fw\Core\Router;
use PHPUnit\Framework\TestCase;

final class AgentAccessibleScannerTest extends TestCase
{
    public function testScanMapsToolNameToNamedRoutes(): void
    {
        $router = new Router();
        $router->get('/tickets', [ScannerFixtureController::class, 'process'], 'tickets.process');

        $scanner = new AgentAccessibleScanner($router);
        $map = $scanner->scan();

        $this->assertArrayHasKey('process_ticket_queue', $map);
        $this->assertCount(1, $map['process_ticket_queue']);
        $this->assertSame('tickets.process', $map['process_ticket_queue'][0]['name']);
        $this->assertSame('GET', $map['process_ticket_queue'][0]['method']);
        $this->assertSame('/tickets', $map['process_ticket_queue'][0]['path']);
    }

    public function testScanSkipsRoutesWithoutAttribute(): void
    {
        $router = new Router();
        $router->get('/home', [ScannerFixtureController::class, 'home'], 'home');

        $scanner = new AgentAccessibleScanner($router);
        $map = $scanner->scan();

        $this->assertSame([], $map);
    }

    public function testScanSkipsRoutesWithoutName(): void
    {
        $router = new Router();
        $router->get('/tickets', [ScannerFixtureController::class, 'process']);

        $scanner = new AgentAccessibleScanner($router);
        $map = $scanner->scan();

        $this->assertSame([], $map);
    }

    public function testScanCollectsMultipleRoutesPerTool(): void
    {
        $router = new Router();
        $router->get('/tickets', [ScannerFixtureController::class, 'process'], 'tickets.process');
        $router->post('/tickets/run', [ScannerFixtureController::class, 'process'], 'tickets.run');

        $scanner = new AgentAccessibleScanner($router);
        $map = $scanner->scan();

        $this->assertCount(2, $map['process_ticket_queue']);
    }

    public function testScanSupportsRepeatedAttributes(): void
    {
        $router = new Router();
        $router->get('/multi', [ScannerFixtureController::class, 'both'], 'multi');

        $scanner = new AgentAccessibleScanner($router);
        $map = $scanner->scan();

        $this->assertArrayHasKey('tool_a', $map);
        $this->assertArrayHasKey('tool_b', $map);
    }

    public function testForToolReturnsSingleToolRoutes(): void
    {
        $router = new Router();
        $router->get('/tickets', [ScannerFixtureController::class, 'process'], 'tickets.process');

        $scanner = new AgentAccessibleScanner($router);

        $this->assertCount(1, $scanner->forTool('process_ticket_queue'));
        $this->assertSame([], $scanner->forTool('unknown_tool'));
    }
}

final class ScannerFixtureController
{
    #[AgentAccessible(tool: 'process_ticket_queue')]
    public function process(): void {}

    public function home(): void {}

    #[AgentAccessible(tool: 'tool_a')]
    #[AgentAccessible(tool: 'tool_b')]
    public function both(): void {}
}
