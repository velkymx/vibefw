<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * M2: Connection::getInstance() config mismatch check is key-order sensitive.
 * Two logically identical configs with different key order must NOT throw.
 */
final class ConnectionConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();
    }

    #[Test]
    public function sameConfigDifferentKeyOrderDoesNotThrow(): void
    {
        $config1 = ['driver' => 'sqlite', 'database' => ':memory:'];
        $config2 = ['database' => ':memory:', 'driver' => 'sqlite']; // same, different order

        Connection::getInstance($config1);

        // Must not throw LogicException('Connection config mismatch')
        $this->expectNotToPerformAssertions();
        Connection::getInstance($config2);
    }

    #[Test]
    public function genuinelyDifferentConfigThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/config mismatch/i');

        Connection::getInstance(['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::getInstance(['driver' => 'sqlite', 'database' => '/other.db']);
    }
}
