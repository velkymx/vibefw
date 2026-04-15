<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Bus\Command;
use Fw\Bus\CommandBus;
use Fw\Bus\Handler;
use Fw\Bus\Query;
use Fw\Bus\QueryBus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Test commands and handlers
final readonly class CreateTestUser implements Command
{
    public function __construct(
        public string $email,
        public string $name
    ) {}
}

final class CreateTestUserHandler implements Handler
{
    public function handle(CreateTestUser $command): array
    {
        return [
            'id' => 'user-123',
            'email' => $command->email,
            'name' => $command->name,
        ];
    }
}

final readonly class GetTestUser implements Query
{
    public function __construct(
        public string $id
    ) {}
}

final class GetTestUserHandler implements Handler
{
    public function handle(GetTestUser $query): ?array
    {
        if ($query->id === 'user-123') {
            return ['id' => 'user-123', 'name' => 'John'];
        }
        return null;
    }
}

final readonly class FailingCommand implements Command {}

final class FailingCommandHandler implements Handler
{
    public function handle(FailingCommand $command): never
    {
        throw new \RuntimeException('Command failed');
    }
}

final readonly class UnhandledCommand implements Command {}

final class CommandBusTest extends TestCase
{
    // ==========================================
    // COMMAND BUS TESTS
    // ==========================================

    public function testDispatchWithRegisteredHandler(): void
    {
        $bus = new CommandBus();
        $bus->register(CreateTestUser::class, new CreateTestUserHandler());

        $result = $bus->dispatch(new CreateTestUser('john@example.com', 'John'));

        $this->assertTrue($result->isOk());
        $user = $result->unwrapOr(null);
        $this->assertEquals('john@example.com', $user['email']);
        $this->assertEquals('John', $user['name']);
    }

    public function testDispatchWithHandlerClassName(): void
    {
        $bus = new CommandBus();
        $bus->register(CreateTestUser::class, CreateTestUserHandler::class);

        $result = $bus->dispatch(new CreateTestUser('jane@example.com', 'Jane'));

        $this->assertTrue($result->isOk());
        $this->assertEquals('Jane', $result->unwrapOr(null)['name']);
    }

    public function testDispatchWithCallableHandler(): void
    {
        $bus = new CommandBus();
        $bus->register(CreateTestUser::class, function (CreateTestUser $cmd) {
            return ['handled' => true, 'email' => $cmd->email];
        });

        $result = $bus->dispatch(new CreateTestUser('test@example.com', 'Test'));

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrapOr(null)['handled']);
    }

    public function testDispatchWithContainerResolver(): void
    {
        $handler = new CreateTestUserHandler();
        $container = fn(string $class) => $handler;

        $bus = new CommandBus($container);
        $bus->register(CreateTestUser::class, CreateTestUserHandler::class);

        $result = $bus->dispatch(new CreateTestUser('john@example.com', 'John'));

        $this->assertTrue($result->isOk());
    }

    public function testDispatchReturnsErrOnException(): void
    {
        $bus = new CommandBus();
        $bus->register(FailingCommand::class, FailingCommandHandler::class);

        $result = $bus->dispatch(new FailingCommand());

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\RuntimeException::class, $result->unwrapErr());
        $this->assertEquals('Command failed', $result->unwrapErr()->getMessage());
    }

    public function testDispatchSyncThrowsOnError(): void
    {
        $bus = new CommandBus();
        $bus->register(FailingCommand::class, FailingCommandHandler::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command failed');

        $bus->dispatchSync(new FailingCommand());
    }

    public function testDispatchWithUnregisteredHandlerThrows(): void
    {
        $bus = new CommandBus();

        $result = $bus->dispatch(new UnhandledCommand());

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(InvalidArgumentException::class, $result->unwrapErr());
    }

    public function testUnregisteredHandlerErrorMentionsExpectedConventionClass(): void
    {
        // CB-02: error must tell the developer what convention-based class to create.
        $bus = new CommandBus();

        $result = $bus->dispatch(new UnhandledCommand());

        $err = $result->unwrapErr();
        $this->assertInstanceOf(InvalidArgumentException::class, $err);
        // Must mention the expected handler class name (UnhandledCommand -> UnhandledCommandHandler)
        $this->assertStringContainsString('UnhandledCommandHandler', $err->getMessage());
    }

    public function testMiddlewareIsExecuted(): void
    {
        $log = [];

        $bus = new CommandBus();
        $bus->register(CreateTestUser::class, CreateTestUserHandler::class);

        $bus->middleware(function ($command, $next) use (&$log) {
            $log[] = 'before';
            $result = $next($command);
            $log[] = 'after';
            return $result;
        });

        $bus->dispatch(new CreateTestUser('test@example.com', 'Test'));

        $this->assertEquals(['before', 'after'], $log);
    }

    public function testMultipleMiddlewareInOrder(): void
    {
        $log = [];

        $bus = new CommandBus();
        $bus->register(CreateTestUser::class, CreateTestUserHandler::class);

        $bus->middleware(function ($command, $next) use (&$log) {
            $log[] = 'first-before';
            $result = $next($command);
            $log[] = 'first-after';
            return $result;
        });

        $bus->middleware(function ($command, $next) use (&$log) {
            $log[] = 'second-before';
            $result = $next($command);
            $log[] = 'second-after';
            return $result;
        });

        $bus->dispatch(new CreateTestUser('test@example.com', 'Test'));

        $this->assertEquals([
            'first-before',
            'second-before',
            'second-after',
            'first-after',
        ], $log);
    }

    public function testRegisterReturnsFluentInterface(): void
    {
        $bus = new CommandBus();

        $result = $bus->register(CreateTestUser::class, CreateTestUserHandler::class);

        $this->assertSame($bus, $result);
    }

    // ==========================================
    // QUERY BUS TESTS
    // ==========================================

    public function testQueryBusDispatch(): void
    {
        $bus = new QueryBus();
        $bus->register(GetTestUser::class, GetTestUserHandler::class);

        $result = $bus->dispatch(new GetTestUser('user-123'));

        $this->assertTrue($result->isOk());
        $this->assertEquals('John', $result->unwrapOr(null)['name']);
    }

    public function testQueryBusDispatchReturnsNullForMissing(): void
    {
        $bus = new QueryBus();
        $bus->register(GetTestUser::class, GetTestUserHandler::class);

        $result = $bus->dispatch(new GetTestUser('nonexistent'));

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrapOr(null));
    }

    public function testQueryBusWithCallableHandler(): void
    {
        $bus = new QueryBus();
        $bus->register(GetTestUser::class, function (GetTestUser $query) {
            return ['id' => $query->id, 'name' => 'Queried'];
        });

        $result = $bus->dispatch(new GetTestUser('abc'));

        $this->assertTrue($result->isOk());
        $this->assertEquals('Queried', $result->unwrapOr(null)['name']);
    }

    public function testQueryBusMiddleware(): void
    {
        $cached = null;

        $bus = new QueryBus();
        $bus->register(GetTestUser::class, GetTestUserHandler::class);

        // Simple caching middleware
        $bus->middleware(function ($query, $next) use (&$cached) {
            $key = serialize($query);
            if ($cached !== null && $cached['key'] === $key) {
                return $cached['value'];
            }
            $result = $next($query);
            $cached = ['key' => $key, 'value' => $result];
            return $result;
        });

        // First call - cache miss
        $result1 = $bus->dispatch(new GetTestUser('user-123'));

        // Second call - should use cache
        $result2 = $bus->dispatch(new GetTestUser('user-123'));

        $this->assertTrue($result1->isOk());
        $this->assertTrue($result2->isOk());
        $this->assertEquals($result1->unwrapOr(null), $result2->unwrapOr(null));
    }
}
