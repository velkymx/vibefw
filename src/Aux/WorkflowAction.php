<?php

declare(strict_types=1);

namespace Fw\Aux;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\DeliverAgentNotificationJob;
use Fw\Bus\Command;
use Fw\Bus\CommandBus;
use Fw\Bus\Query;
use Fw\Bus\QueryBus;
use Fw\Queue\Queue;
use Fw\Support\Result;

abstract class WorkflowAction
{
    public function __construct(
        protected readonly CommandBus $commands,
        protected readonly QueryBus $queries,
        protected readonly ?Queue $queue = null,
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

    final protected function notify(AgentNotification $notification, string $channel = 'log'): string
    {
        if ($this->queue === null) {
            throw new \RuntimeException(
                'WorkflowAction::notify() requires a Queue. Inject one via the constructor.'
            );
        }

        return $this->queue->dispatch(new DeliverAgentNotificationJob($notification, $channel));
    }
}
