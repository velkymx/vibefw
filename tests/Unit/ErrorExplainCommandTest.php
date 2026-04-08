<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorExplainCommandTest extends TestCase
{
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();
        $this->console = new ConsoleApp(dirname(__DIR__, 2));
    }

    #[Test]
    public function commandIsRegistered(): void
    {
        $commands = $this->console->getCommands();

        $found = false;
        foreach ($commands as $command) {
            if ($command->getName() === 'error:explain') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function explainsMassAssignmentError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'Mass assignment violation on Post']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('fillable', $output);
    }

    #[Test]
    public function explainsClassNotFoundError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'Class "App\\Controllers\\PostController" not found']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('make:', $output);
    }

    #[Test]
    public function explainsRemovedMethodError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'Call to undefined method unwrap()']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('match()', $output);
    }

    #[Test]
    public function explainsValidationError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'ValidationException']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('FormRequest', $output);
    }

    #[Test]
    public function handlesSqlConstraintError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'SQLSTATE[23000]: Integrity constraint violation']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('foreign key', $output);
    }

    #[Test]
    public function handlesUnknownError(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain', 'something completely random']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No known pattern', $output);
    }

    #[Test]
    public function failsWithoutMessage(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'error:explain']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }
}
