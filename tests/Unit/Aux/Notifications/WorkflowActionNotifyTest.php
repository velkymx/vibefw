<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\DeliverAgentNotificationJob;
use Fw\Aux\Notifications\Severity;
use Fw\Aux\WorkflowAction;
use Fw\Aux\WorkflowResult;
use Fw\Bus\CommandBus;
use Fw\Bus\QueryBus;
use Fw\Queue\DriverInterface;
use Fw\Queue\JobInterface;
use Fw\Queue\Queue;
use Fw\Queue\SyncDriver;
use PHPUnit\Framework\TestCase;

final class WorkflowActionNotifyTest extends TestCase
{
    protected function tearDown(): void
    {
        DeliverAgentNotificationJob::resetDeliverer();
    }

    public function testNotifyDispatchesJobThroughQueue(): void
    {
        $dispatched = [];
        DeliverAgentNotificationJob::setDeliverer(function (AgentNotification $n, string $channel) use (&$dispatched) {
            $dispatched[] = [$channel, $n->subject, $n->severity];
        });

        $queue = new Queue(new SyncDriver());
        $workflow = $this->makeWorkflow($queue);

        $result = $workflow->execute([]);

        $this->assertFalse($result->isError);
        $this->assertSame([['log', 'Queue drained', Severity::Info]], $dispatched);
    }

    public function testNotifyWithoutQueueThrows(): void
    {
        $workflow = $this->makeWorkflow(null);

        $this->expectException(\RuntimeException::class);
        $workflow->execute([]);
    }

    private function makeWorkflow(?Queue $queue): WorkflowAction
    {
        return new class(new CommandBus(), new QueryBus(), $queue) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $this->notify(new AgentNotification('ops@example.com', 'Queue drained', 'done'));
                return WorkflowResult::success();
            }
        };
    }

    public function testNotifyCustomChannelPropagates(): void
    {
        $seen = [];
        DeliverAgentNotificationJob::setDeliverer(function (AgentNotification $n, string $channel) use (&$seen) {
            $seen[] = $channel;
        });

        $queue = new Queue(new SyncDriver());
        $workflow = new class(new CommandBus(), new QueryBus(), $queue) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $this->notify(new AgentNotification('r', 's', 'b'), channel: 'webhook');
                return WorkflowResult::success();
            }
        };

        $workflow->execute([]);

        $this->assertSame(['webhook'], $seen);
    }

    public function testJobIsOfExpectedType(): void
    {
        $driver = new class implements DriverInterface {
            public ?JobInterface $captured = null;
            public function push(JobInterface $job): string { $this->captured = $job; return 'job-id'; }
            public function later(int $delay, JobInterface $job): string { return $this->push($job); }
            public function pop(string $queue = 'default'): ?array { return null; }
            public function delete(string $jobId): bool { return true; }
            public function release(string $jobId, int $delay = 0): bool { return true; }
            public function size(string $queue = 'default'): int { return 0; }
            public function clear(string $queue = 'default'): int { return 0; }
        };

        $queue = new Queue($driver);
        $workflow = new class(new CommandBus(), new QueryBus(), $queue) extends WorkflowAction {
            public function execute(array $input): WorkflowResult
            {
                $this->notify(new AgentNotification('r', 's', 'b', Severity::Error));
                return WorkflowResult::success();
            }
        };
        $workflow->execute([]);

        $this->assertInstanceOf(DeliverAgentNotificationJob::class, $driver->captured);
    }
}
