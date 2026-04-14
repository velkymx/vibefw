<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\EventLoop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventLoopStreamIdTest extends TestCase
{
    private EventLoop $loop;

    /** @var resource[] */
    private array $streams = [];

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
    }

    /** @return resource */
    private function makeStream(): mixed
    {
        $s = fopen('php://memory', 'r+');
        $this->assertIsResource($s);
        $this->streams[] = $s;
        return $s;
    }

    #[Test]
    public function addReadStreamReturnsUniqueId(): void
    {
        $s1 = $this->makeStream();
        $s2 = $this->makeStream();

        $id1 = $this->loop->addReadStream($s1, fn () => null);
        $id2 = $this->loop->addReadStream($s2, fn () => null);

        $this->assertIsInt($id1);
        $this->assertIsInt($id2);
        $this->assertNotSame($id1, $id2);
    }

    #[Test]
    public function addWriteStreamReturnsUniqueId(): void
    {
        $s1 = $this->makeStream();
        $s2 = $this->makeStream();

        $id1 = $this->loop->addWriteStream($s1, fn () => null);
        $id2 = $this->loop->addWriteStream($s2, fn () => null);

        $this->assertIsInt($id1);
        $this->assertIsInt($id2);
        $this->assertNotSame($id1, $id2);
    }

    #[Test]
    public function secondAddReadStreamDoesNotSilentlyOverwriteFirst(): void
    {
        $fired = [];

        $s1 = $this->makeStream();
        $s2 = $this->makeStream();

        // Simulate same int cast by using real distinct resources —
        // collision can't be forced in a test, so verify both callbacks survive
        $this->loop->addReadStream($s1, function () use (&$fired) { $fired[] = 'a'; });
        $this->loop->addReadStream($s2, function () use (&$fired) { $fired[] = 'b'; });

        // Both entries must exist (not one overwriting the other)
        $this->assertSame(2, $this->loop->getReadStreamCount());
    }

    #[Test]
    public function removeReadStreamByIdRemovesCorrectEntry(): void
    {
        $s1 = $this->makeStream();
        $s2 = $this->makeStream();

        $id1 = $this->loop->addReadStream($s1, fn () => null);
        $this->loop->addReadStream($s2, fn () => null);

        $this->loop->removeReadStream($id1);

        $this->assertSame(1, $this->loop->getReadStreamCount());
    }

    #[Test]
    public function removeWriteStreamByIdRemovesCorrectEntry(): void
    {
        $s1 = $this->makeStream();
        $s2 = $this->makeStream();

        $id1 = $this->loop->addWriteStream($s1, fn () => null);
        $this->loop->addWriteStream($s2, fn () => null);

        $this->loop->removeWriteStream($id1);

        $this->assertSame(1, $this->loop->getWriteStreamCount());
    }
}
