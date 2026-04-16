<?php

declare(strict_types=1);

namespace Fw\Aux;

use Fw\Bus\Command;
use Fw\Bus\CommandBus;
use Fw\Bus\Query;
use Fw\Bus\QueryBus;
use Fw\Support\Result;

abstract class WorkflowAction
{
    public function __construct(
        protected readonly CommandBus $commands,
        protected readonly QueryBus $queries,
    ) {}

    abstract public function execute(array $input): WorkflowResult;

    final protected function dispatch(Command $command): Result
    {
        return $this->commands->dispatch($command);
    }

    final protected function query(Query $query): Result
    {
        return $this->queries->dispatch($query);
    }
}
