<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Model\MassAssignmentException;
use Fw\Model\ModelNotFoundException;
use Fw\Auth\ForbiddenException;
use Fw\Core\PrescriptiveException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrescriptiveExceptionTest extends TestCase
{
    #[Test]
    public function massAssignmentExceptionImplementsInterface(): void
    {
        $e = new MassAssignmentException('App\\Models\\Post', ['admin']);

        $this->assertInstanceOf(PrescriptiveException::class, $e);
        $this->assertNotEmpty($e->getFixCommand());
        $this->assertStringContainsString('php fw', $e->getFixCommand());
    }

    #[Test]
    public function massAssignmentExceptionMessageIncludesFix(): void
    {
        $e = new MassAssignmentException('App\\Models\\Post', ['admin']);

        $this->assertStringContainsString('Fix:', $e->getMessage());
        $this->assertStringContainsString('$fillable', $e->getMessage());
    }

    #[Test]
    public function modelNotFoundExceptionImplementsInterface(): void
    {
        $e = new ModelNotFoundException('App\\Models\\Post', 42);

        $this->assertInstanceOf(PrescriptiveException::class, $e);
        $this->assertNotEmpty($e->getFixCommand());
        $this->assertStringContainsString('model:inspect', $e->getFixCommand());
    }

    #[Test]
    public function modelNotFoundExceptionMessageIncludesFix(): void
    {
        $e = new ModelNotFoundException('App\\Models\\Post', 42);

        $this->assertStringContainsString('Fix:', $e->getMessage());
        $this->assertStringContainsString('42', $e->getMessage());
    }

    #[Test]
    public function forbiddenExceptionImplementsInterface(): void
    {
        $e = new ForbiddenException();

        $this->assertInstanceOf(PrescriptiveException::class, $e);
        $this->assertNotEmpty($e->getFixCommand());
        $this->assertStringContainsString('routes:list', $e->getFixCommand());
    }

    #[Test]
    public function forbiddenExceptionMessageIncludesFix(): void
    {
        $e = new ForbiddenException();

        $this->assertStringContainsString('Fix:', $e->getMessage());
    }
}
