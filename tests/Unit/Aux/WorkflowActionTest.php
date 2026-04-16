<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\WorkflowAction;
use Fw\Aux\WorkflowResult;
use Fw\Bus\Command;
use Fw\Bus\CommandBus;
use Fw\Bus\Query;
use Fw\Bus\QueryBus;
use Fw\Support\Result;
use PHPUnit\Framework\TestCase;

final readonly class TestCommand implements Command
{
    public function __construct(public string $name) {}
}

final readonly class TestQuery implements Query
{
    public function __construct(public int $id) {}
}

final class WorkflowActionTest extends TestCase
{
    private CommandBus $commandBus;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        $this->commandBus = new CommandBus();
        $this->queryBus = new QueryBus();
    }

    public function testExecuteReturnsWorkflowResult(): void
    {
        $workflow = new class($this->commandBus, $this->queryBus) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                return WorkflowResult::success(['test' => 'data']);
            }
        };

        $result = $workflow->execute(['test' => 'input']);

        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertFalse($result->isError);
    }

    public function testDispatchReturnsErrOnMissingHandler(): void
    {
        $workflow = new class($this->commandBus, $this->queryBus) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $result = $this->dispatch(new TestCommand('test'));

                return $result->match(
                    ok: fn() => WorkflowResult::success(['dispatched' => true]),
                    err: fn($e) => WorkflowResult::error($e->getMessage()),
                );
            }
        };

        $result = $workflow->execute([]);

        $this->assertTrue($result->isError);
    }

    public function testQueryReturnsErrOnMissingHandler(): void
    {
        $workflow = new class($this->commandBus, $this->queryBus) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $result = $this->query(new TestQuery(1));

                return $result->match(
                    ok: fn($data) => WorkflowResult::success(['data' => $data]),
                    err: fn($e) => WorkflowResult::error($e->getMessage()),
                );
            }
        };

        $result = $workflow->execute(['id' => 1]);

        $this->assertTrue($result->isError);
    }

    public function testErrorResultOnDispatchFailure(): void
    {
        $workflow = new class($this->commandBus, $this->queryBus) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $result = $this->dispatch(new TestCommand('fail'));

                return $result->match(
                    ok: fn() => WorkflowResult::success(),
                    err: fn($e) => WorkflowResult::error($e->getMessage()),
                );
            }
        };

        $result = $workflow->execute([]);

        $this->assertTrue($result->isError);
    }
}
