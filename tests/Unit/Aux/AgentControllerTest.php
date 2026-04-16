<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Exceptions\ToolNotFoundException;
use Fw\Aux\Exceptions\ToolValidationException;
use Fw\Aux\Http\AgentController;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Support\Result;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentControllerTest extends TestCase
{
    private ToolRegistry $registry;
    private AgentController $controller;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $this->controller = $this->makeController($this->registry);
    }

    // ── B1: constructor must accept Application $app as sole parameter ────────

    public function testConstructorAcceptsApplicationAsOnlyParameter(): void
    {
        $rc = new ReflectionClass(AgentController::class);
        $params = $rc->getConstructor()->getParameters();

        $this->assertCount(1, $params, 'AgentController constructor must accept exactly one parameter');
        $this->assertSame('app', $params[0]->getName());
        $this->assertSame(Application::class, $params[0]->getType()->getName());
    }

    // ── B2: invoke() must use unwrapOr(null), not the non-existent unwrap() ──

    public function testInvokeReturnsMcpContentOnSuccess(): void
    {
        $this->registry->register(new Tool(
            name: 'echo_tool',
            description: 'Echoes back',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(completed: [['done' => true]]),
        ));

        $request = $this->makeRequest(['POST', '/agent/tools/echo_tool']);

        $response = $this->controller->invoke($request, 'echo_tool');

        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('content', $body);
        $this->assertEquals('text', $body['content'][0]['type']);
    }

    public function testInvokeReturns404ForUnknownTool(): void
    {
        $request = $this->makeRequest(['POST', '/agent/tools/missing']);

        $response = $this->controller->invoke($request, 'missing');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(-32001, $body['error']['code']);
    }

    public function testInvokeReturns422ForValidationError(): void
    {
        $this->registry->register(new Tool(
            name: 'strict_tool',
            description: 'Needs id',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(),
        ));

        $request = $this->makeRequest(['POST', '/agent/tools/strict_tool']);

        $response = $this->controller->invoke($request, 'strict_tool');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(-32002, $body['error']['code']);
    }

    // ── S1: abilities come from TokenGuard, not X-Agent-Abilities header ──────

    public function testIndexIgnoresAgentAbilitiesHeader(): void
    {
        $this->registry->register(new Tool(
            name: 'admin_tool',
            description: 'Admin-only',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        ));

        // Even with the header set, tool is not returned — header must be ignored
        $_SERVER['HTTP_X_AGENT_ABILITIES'] = 'admin';
        $request = $this->makeRequest(['GET', '/agent/tools']);

        $response = $this->controller->index($request);
        $body = json_decode($response->getBody(), true);

        // No token in context → TokenGuard::tokenAbilities() returns [] → admin tool hidden
        $this->assertCount(0, $body['tools']);

        unset($_SERVER['HTTP_X_AGENT_ABILITIES']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeController(ToolRegistry $registry): AgentController
    {
        $rc = new ReflectionClass(AgentController::class);
        $controller = $rc->newInstanceWithoutConstructor();

        // Inject ToolRegistry directly (bypasses Application DI).
        // $app is not injected — index()/invoke() only use $this->tools and $this->json(),
        // and json() does not access $this->app.
        $prop = $rc->getProperty('tools');
        $prop->setValue($controller, $registry);

        return $controller;
    }

    private function makeRequest(array $server = []): Request
    {
        [$method, $uri] = $server;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        return new Request();
    }
}
