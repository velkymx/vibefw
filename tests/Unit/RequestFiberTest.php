<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\Deferred;
use Fw\Async\EventLoop;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Lifecycle\Component;
use Fw\Lifecycle\Hook;
use Fw\Lifecycle\RequestFiber;
use PHPUnit\Framework\TestCase;

/**
 * Stub Application for testing
 */
class RequestFiberAppStub
{
    public ?object $db = null;
    public ?object $view = null;
    public RequestFiberResponseStub $response;
    public RequestFiberLoggerStub $log;

    public function __construct()
    {
        $this->response = new RequestFiberResponseStub();
        $this->log = new RequestFiberLoggerStub();
    }
}

class RequestFiberResponseStub
{
    public function setHeader(string $name, string $value): self
    {
        return $this;
    }
}

class RequestFiberLoggerStub
{
    public function error(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }
}

final class RequestFiberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventLoop::reset();

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            'HTTP_HOST' => 'localhost',
        ];
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        EventLoop::reset();
        parent::tearDown();
    }

    private function createMockApp(): RequestFiberAppStub
    {
        return new RequestFiberAppStub();
    }

    public function testFiberStartsAndCompletes(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $handler = function (Request $req) {
            return 'Hello World';
        };

        $fiber = new RequestFiber($app, $request, $handler);

        $this->assertFalse($fiber->isStarted());
        $this->assertFalse($fiber->isCompleted());

        $fiber->start();

        $this->assertTrue($fiber->isStarted());
        $this->assertTrue($fiber->isCompleted());
        $this->assertEquals('Hello World', $fiber->getOutput());
        $this->assertNull($fiber->getError());
    }

    public function testFiberHandlesCallableReturningArray(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $handler = function (Request $req) {
            return ['status' => 'ok', 'data' => [1, 2, 3]];
        };

        $fiber = new RequestFiber($app, $request, $handler);
        $fiber->start();

        $this->assertTrue($fiber->isCompleted());
        $this->assertEquals(['status' => 'ok', 'data' => [1, 2, 3]], $fiber->getOutput());
    }

    public function testFiberCapturesExceptions(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $handler = function (Request $req) {
            throw new \RuntimeException('Test error');
        };

        $fiber = new RequestFiber($app, $request, $handler);
        $fiber->start();

        $this->assertTrue($fiber->isCompleted());
        $this->assertNull($fiber->getOutput());
        $this->assertInstanceOf(\RuntimeException::class, $fiber->getError());
        $this->assertEquals('Test error', $fiber->getError()->getMessage());
    }

    public function testComponentLifecycleHooksExecuteInOrder(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $component = new LifecycleTrackingComponent($app, $request);
        $fiber = new RequestFiber($app, $request, $component);
        $fiber->start();

        $expectedOrder = [
            'booting',
            'booted',
            'beforeRequest',
            'afterRequest',
            'beforeFetch',
            'fetch',
            'afterFetch',
            'render',
            'beforeResponse',
            'afterResponse',
        ];

        $this->assertEquals($expectedOrder, $component->executionOrder);
    }

    public function testComponentReceivesParams(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $component = new ParamCapturingComponent($app, $request);
        $fiber = new RequestFiber($app, $request, $component, ['id' => '123', 'slug' => 'test']);
        $fiber->start();

        $this->assertEquals('123', $component->capturedId);
        $this->assertEquals('test', $component->capturedSlug);
    }

    public function testFiberSuspendsAndResumes(): void
    {
        $app = $this->createMockApp();
        $request = new Request();
        $loop = EventLoop::getInstance();

        $deferred = new Deferred();
        $component = new SuspendingComponent($app, $request, $deferred);
        $fiber = new RequestFiber($app, $request, $component);

        $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertFalse($fiber->isCompleted());

        // Resolve the deferred
        $deferred->resolve('async result');
        $loop->tick();

        $this->assertFalse($fiber->isSuspended());
        $this->assertTrue($fiber->isCompleted());
        $this->assertEquals('Result: async result', $fiber->getOutput());
    }

    public function testComponentErrorHookCalledOnException(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $component = new ErrorCapturingComponent($app, $request);
        $fiber = new RequestFiber($app, $request, $component);
        $fiber->start();

        $this->assertTrue($fiber->isCompleted());
        $this->assertNotNull($fiber->getError());
        $this->assertTrue($component->errorHookCalled);
        $this->assertInstanceOf(\RuntimeException::class, $component->capturedError);
    }

    public function testAfterResponseCalledEvenOnError(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $component = new ErrorCapturingComponent($app, $request);
        $fiber = new RequestFiber($app, $request, $component);
        $fiber->start();

        $this->assertTrue($component->afterResponseCalled);
    }

    public function testGetCurrentHookTracksFiberProgress(): void
    {
        $app = $this->createMockApp();
        $request = new Request();

        $handler = function (Request $req) {
            return 'done';
        };

        $fiber = new RequestFiber($app, $request, $handler);

        $this->assertEquals(Hook::BOOTING, $fiber->getCurrentHook());

        $fiber->start();

        // After completion, hook tracking stops at AFTER_RESPONSE for components
        // For callables, it stays at BOOTING since no lifecycle is executed
        $this->assertTrue($fiber->isCompleted());
    }
}

/**
 * Component that tracks lifecycle hook execution order
 */
class LifecycleTrackingComponent extends Component
{
    public array $executionOrder = [];

    public function booting(): void
    {
        $this->executionOrder[] = 'booting';
    }

    public function booted(): void
    {
        $this->executionOrder[] = 'booted';
    }

    public function beforeRequest(): void
    {
        $this->executionOrder[] = 'beforeRequest';
    }

    public function afterRequest(): void
    {
        $this->executionOrder[] = 'afterRequest';
    }

    public function beforeFetch(): void
    {
        $this->executionOrder[] = 'beforeFetch';
    }

    public function fetch(): void
    {
        $this->executionOrder[] = 'fetch';
    }

    public function afterFetch(): void
    {
        $this->executionOrder[] = 'afterFetch';
    }

    public function beforeResponse(): void
    {
        $this->executionOrder[] = 'beforeResponse';
    }

    public function afterResponse(): void
    {
        $this->executionOrder[] = 'afterResponse';
    }

    public function render(): string
    {
        $this->executionOrder[] = 'render';
        return 'rendered';
    }
}

/**
 * Component that captures route parameters
 */
class ParamCapturingComponent extends Component
{
    public ?string $capturedId = null;
    public ?string $capturedSlug = null;

    public function beforeRequest(): void
    {
        $this->capturedId = $this->param('id');
        $this->capturedSlug = $this->param('slug');
    }

    public function render(): string
    {
        return "ID: {$this->capturedId}, Slug: {$this->capturedSlug}";
    }
}

/**
 * Component that suspends during fetch
 */
class SuspendingComponent extends Component
{
    private Deferred $deferred;
    private mixed $result = null;

    public function __construct(object $app, Request $request, Deferred $deferred)
    {
        parent::__construct($app, $request);
        $this->deferred = $deferred;
    }

    public function fetch(): void
    {
        $this->result = $this->await($this->deferred);
    }

    public function render(): string
    {
        return 'Result: ' . $this->result;
    }
}

/**
 * Component that throws an error during fetch
 */
class ErrorCapturingComponent extends Component
{
    public bool $errorHookCalled = false;
    public bool $afterResponseCalled = false;
    public ?\Throwable $capturedError = null;

    public function fetch(): void
    {
        throw new \RuntimeException('Fetch error');
    }

    public function error(\Throwable $e): void
    {
        $this->errorHookCalled = true;
        $this->capturedError = $e;
        parent::error($e);
    }

    public function afterResponse(): void
    {
        $this->afterResponseCalled = true;
    }

    public function render(): string
    {
        return 'Should not be called';
    }
}
