<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Config\ConfigValidator;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register schemas for testing - use unique names to avoid cross-test pollution
        ConfigValidator::registerSchema('test_db', [
            'driver' => ['required' => true, 'type' => 'string', 'enum' => ['mysql', 'sqlite', 'pgsql']],
            'host'   => ['required' => false, 'type' => 'string'],
        ]);

        ConfigValidator::registerSchema('test_app', [
            'debug' => ['required' => true, 'type' => 'bool'],
            'name'  => ['required' => true, 'type' => 'string'],
        ]);
    }

    public function testValidateAllCollectsErrorsFromMultipleConfigs(): void
    {
        // Both configs are invalid — errors from both must be collected
        $result = ConfigValidator::validateAll([
            'test_db'  => ['driver' => 'invalid_driver'],  // enum violation
            'test_app' => [],                               // missing required keys
        ]);

        $this->assertTrue($result->isErr(), 'validateAll must return Err when any config is invalid');

        $errors = $result->unwrapErr();
        $this->assertIsArray($errors);
        $this->assertGreaterThanOrEqual(2, count($errors), 'Must collect errors from all failing configs');

        // Errors from test_db must be present
        $hasDbError = array_filter($errors, fn($e) => str_contains($e, 'test_db'));
        $this->assertNotEmpty($hasDbError, 'Errors from test_db must be present');

        // Errors from test_app must be present
        $hasAppError = array_filter($errors, fn($e) => str_contains($e, 'test_app'));
        $this->assertNotEmpty($hasAppError, 'Errors from test_app must be present');
    }

    public function testValidateAllReturnsOkWhenAllConfigsValid(): void
    {
        $result = ConfigValidator::validateAll([
            'test_db'  => ['driver' => 'mysql'],
            'test_app' => ['debug' => true, 'name' => 'TestApp'],
        ]);

        $this->assertTrue($result->isOk());
    }

    public function testValidateAllWithSingleInvalidConfigReturnsErr(): void
    {
        $result = ConfigValidator::validateAll([
            'test_db'  => ['driver' => 'mysql'],    // valid
            'test_app' => ['debug' => true],         // missing required 'name'
        ]);

        $this->assertTrue($result->isErr(), 'Must return Err when any config fails');

        $errors = $result->unwrapErr();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('test_app', $errors[0]);
    }
}
