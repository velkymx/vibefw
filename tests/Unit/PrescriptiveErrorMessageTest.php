<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\ForbiddenException;
use Fw\Core\MethodNotAllowed;
use Fw\Core\RouteNotFound;
use Fw\Model\MassAssignmentException;
use Fw\Model\ModelNotFoundException;
use Fw\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrescriptiveErrorMessageTest extends TestCase
{
    #[Test]
    public function massAssignmentExceptionIncludesFixCommand(): void
    {
        $e = MassAssignmentException::forAttributes('App\\Models\\Post', ['is_admin', 'role']);

        $this->assertStringContainsString('Mass assignment violation on Post', $e->getMessage());
        $this->assertStringContainsString('[is_admin, role] are not fillable', $e->getMessage());
        $this->assertStringContainsString('php fw add:field Post is_admin string', $e->getMessage());
        $this->assertSame('App\\Models\\Post', $e->model);
        $this->assertSame(['is_admin', 'role'], $e->attributes);
    }

    #[Test]
    public function routeNotFoundIncludesFixCommand(): void
    {
        $e = RouteNotFound::forRequest('GET', '/admin/dashboard');

        $this->assertStringContainsString('No route found for GET /admin/dashboard', $e->getMessage());
        $this->assertStringContainsString('php fw routes:list', $e->getMessage());
        $this->assertSame(404, $e->getCode());
        $this->assertSame('GET', $e->method);
        $this->assertSame('/admin/dashboard', $e->uri);
    }

    #[Test]
    public function methodNotAllowedIncludesFixCommand(): void
    {
        $e = MethodNotAllowed::forRequest('PATCH', '/posts/1', ['GET', 'POST']);

        $this->assertStringContainsString('Method PATCH not allowed for /posts/1', $e->getMessage());
        $this->assertStringContainsString('Allowed: GET, POST', $e->getMessage());
        $this->assertStringContainsString('php fw routes:list', $e->getMessage());
        $this->assertSame(405, $e->getCode());
    }

    #[Test]
    public function modelNotFoundExceptionIncludesFixCommand(): void
    {
        $e = ModelNotFoundException::forModel('App\\Models\\Post', 42);

        $this->assertStringContainsString('Post with ID [42] not found', $e->getMessage());
        $this->assertStringContainsString('php fw model:inspect Post', $e->getMessage());
        $this->assertSame(404, $e->getCode());
    }

    #[Test]
    public function modelNotFoundExceptionWithoutIdIncludesFixCommand(): void
    {
        $e = ModelNotFoundException::forModel('App\\Models\\User');

        $this->assertStringContainsString('User not found', $e->getMessage());
        $this->assertStringContainsString('php fw model:inspect User', $e->getMessage());
    }

    #[Test]
    public function validationExceptionIncludesFixCommand(): void
    {
        $e = new ValidationException([
            'email' => ['The email field is required.'],
            'name' => ['The name must be at least 3 characters.'],
        ]);

        $this->assertStringContainsString('The given data was invalid', $e->getMessage());
        $this->assertStringContainsString('Failing fields: email, name', $e->getMessage());
        $this->assertStringContainsString('php fw check', $e->getMessage());
    }

    #[Test]
    public function forbiddenExceptionIncludesFixCommand(): void
    {
        $e = new ForbiddenException();

        $this->assertStringContainsString('unauthorized', $e->getMessage());
        $this->assertStringContainsString('php fw routes:list --middleware', $e->getMessage());
        $this->assertSame(403, $e->getCode());
    }
}
