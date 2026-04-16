<?php

declare(strict_types=1);

namespace Fw\Tests\Feature\Aux;

use App\Models\User;
use Fw\Auth\ApiToken;
use Fw\Auth\TokenGuard;
use Fw\Aux\Http\AgentController;
use Fw\Aux\Http\AgentMiddleware;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Core\Response;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use ReflectionClass;

final class AgentEndpointTest extends TestCase
{
    private ToolRegistry $registry;
    private AgentController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();
        $this->injectDatabase();

        $this->registry = new ToolRegistry();
        $this->registerTestTools();

        $this->controller = $this->makeController();
    }

    protected function tearDown(): void
    {
        TokenGuard::logout();
        ApiToken::resetConfig();
        parent::tearDown();
    }

    // ── AgentMiddleware: 406 Not Acceptable ──────────────────────────────────

    public function testMiddlewareRejects406WithoutJsonAccept(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $this->assertSame(406, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Not Acceptable', $body['title']);
    }

    public function testMiddlewarePassesWithJsonAccept(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('X-AUX-Version', $headers);
    }

    // ── Tool List: shape and ability filtering ───────────────────────────────

    public function testToolListReturnsPublicToolsWithoutAuth(): void
    {
        RequestContext::create(new Request());

        $request = $this->makeJsonRequest('GET', '/agent/tools');
        $response = $this->controller->index($request);

        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('tools', $body);
        $names = array_column($body['tools'], 'name');
        $this->assertContains('public_echo', $names);
        $this->assertNotContains('write_action', $names);
    }

    public function testToolListIncludesGatedToolsWhenAbilitiesMatch(): void
    {
        $this->authenticateWithAbilities(['write']);

        $request = $this->makeJsonRequest('GET', '/agent/tools');
        $response = $this->controller->index($request);

        $body = json_decode($response->getBody(), true);

        $names = array_column($body['tools'], 'name');
        $this->assertContains('public_echo', $names);
        $this->assertContains('write_action', $names);
    }

    public function testToolListHidesGatedToolsWhenAbilitiesMismatch(): void
    {
        $this->authenticateWithAbilities(['read']);

        $request = $this->makeJsonRequest('GET', '/agent/tools');
        $response = $this->controller->index($request);

        $body = json_decode($response->getBody(), true);

        $names = array_column($body['tools'], 'name');
        $this->assertContains('public_echo', $names);
        $this->assertNotContains('write_action', $names);
    }

    public function testToolListShapeMatchesMcpToolsSpec(): void
    {
        RequestContext::create(new Request());

        $request = $this->makeJsonRequest('GET', '/agent/tools');
        $response = $this->controller->index($request);

        $body = json_decode($response->getBody(), true);

        $tool = $body['tools'][0];
        $this->assertArrayHasKey('name', $tool);
        $this->assertArrayHasKey('description', $tool);
        $this->assertArrayHasKey('inputSchema', $tool);
        $this->assertSame('public_echo', $tool['name']);
    }

    // ── Tool Invoke: success, 404, 422 ───────────────────────────────────────

    public function testInvokeReturnsContentOnSuccess(): void
    {
        RequestContext::create(new Request());

        $_POST = ['message' => 'hello'];
        $request = $this->makeJsonRequest('POST', '/agent/tools/public_echo');

        $response = $this->controller->invoke($request, 'public_echo');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('content', $body);
        $this->assertSame('text', $body['content'][0]['type']);

        $payload = json_decode($body['content'][0]['text'], true);
        $this->assertContains(['message' => 'hello', 'echoed' => true], $payload['completed']);
    }

    public function testInvokeReturns404ForUnknownTool(): void
    {
        RequestContext::create(new Request());

        $request = $this->makeJsonRequest('POST', '/agent/tools/nonexistent');
        $response = $this->controller->invoke($request, 'nonexistent');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(-32001, $body['error']['code']);
    }

    public function testInvokeReturns422ForSchemaViolation(): void
    {
        RequestContext::create(new Request());

        $_POST = [];
        $request = $this->makeJsonRequest('POST', '/agent/tools/strict_tool');

        $response = $this->controller->invoke($request, 'strict_tool');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(-32002, $body['error']['code']);
    }

    public function testInvokeReturns404ForGatedToolWithoutAbility(): void
    {
        $this->authenticateWithAbilities(['read']);

        $_POST = [];
        $request = $this->makeJsonRequest('POST', '/agent/tools/write_action');

        $response = $this->controller->invoke($request, 'write_action');

        // Ability-gated tools are invisible — returns 404, not 403
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInvokeSucceedsForGatedToolWithMatchingAbility(): void
    {
        $this->authenticateWithAbilities(['write']);

        $_POST = [];
        $request = $this->makeJsonRequest('POST', '/agent/tools/write_action');

        $response = $this->controller->invoke($request, 'write_action');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('content', $body);
    }

    public function testInvokeReturns500ForHandlerException(): void
    {
        RequestContext::create(new Request());

        $_POST = [];
        $request = $this->makeJsonRequest('POST', '/agent/tools/exploding_tool');

        $response = $this->controller->invoke($request, 'exploding_tool');

        $this->assertSame(500, $response->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function registerTestTools(): void
    {
        $this->registry->register(new Tool(
            name: 'public_echo',
            description: 'Echoes input back',
            inputSchema: ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
            handler: fn(array $input) => WorkflowResult::success(
                completed: [array_merge($input, ['echoed' => true])],
            ),
        ));

        $this->registry->register(new Tool(
            name: 'write_action',
            description: 'Requires write ability',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(completed: [['wrote' => true]]),
            abilities: ['write'],
        ));

        $this->registry->register(new Tool(
            name: 'strict_tool',
            description: 'Requires id parameter',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(),
        ));

        $this->registry->register(new Tool(
            name: 'exploding_tool',
            description: 'Always throws',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Kaboom'),
        ));
    }

    private function makeController(): AgentController
    {
        $rc = new ReflectionClass(AgentController::class);
        $controller = $rc->newInstanceWithoutConstructor();

        $prop = $rc->getProperty('tools');
        $prop->setValue($controller, $this->registry);

        return $controller;
    }

    private function makeJsonRequest(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        return new Request();
    }

    private function createTokensTable(): void
    {
        $this->db->query("
            CREATE TABLE personal_access_tokens (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                abilities TEXT,
                expires_at DATETIME NULL,
                last_used_at DATETIME NULL,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    private function injectDatabase(): void
    {
        \Fw\Model\Model::setConnection($this->db);
    }

    private function createUser(): User
    {
        $id = Str::uuid();
        $this->db->insert('users', [
            'id' => $id,
            'name' => 'Agent Test User',
            'email' => 'agent-' . $id . '@test.com',
            'password' => password_hash('password', PASSWORD_ARGON2ID),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return User::find($id)->unwrapOr(null);
    }

    private function authenticateWithAbilities(array $abilities): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'test-token', $abilities);

        RequestContext::create(new Request());
        TokenGuard::authenticateToken($newToken->plainTextToken);
    }
}
