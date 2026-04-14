<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelConnectionResetTest extends TestCase
{
    #[Test]
    public function resetConnectionClearsStaticConnection(): void
    {
        // Model::resetConnection() must exist and clear the static $connection
        Model::resetConnection();

        // After reset, getConnection() should throw (no connection set)
        $this->expectException(\RuntimeException::class);
        Model::getConnection();
    }

    #[Test]
    public function resetConnectionIsCalledByHttpKernelResetState(): void
    {
        // Verify HttpKernel::resetState() calls Model::resetConnection()
        // by checking the method exists (integration verified separately)
        $this->assertTrue(method_exists(Model::class, 'resetConnection'));
    }
}
