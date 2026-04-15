<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use Fw\Validation\DatabaseRule;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Exists;
use Fw\Validation\Rules\Unique;
use Fw\Validation\ValidationException;
use Fw\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;

final class DatabaseRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createDatabase();
        $this->runMigrations();
        $this->db->insert('users', [
            'id'         => 'u1',
            'name'       => 'Alice',
            'email'      => 'alice@example.com',
            'password'   => 'hashed',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
        parent::tearDown();
    }

    // ---- Exists ----

    #[Test]
    public function existsImplementsDatabaseRule(): void
    {
        $this->assertInstanceOf(DatabaseRule::class, new Exists('users', 'email'));
        $this->assertInstanceOf(Rule::class, new Exists('users', 'email'));
    }

    #[Test]
    public function existsPassesWhenValueFoundInDb(): void
    {
        $validator = new Validator();
        $validated = $validator->validate(
            ['email' => 'alice@example.com'],
            ['email' => [new Exists('users', 'email')]],
        );
        $this->assertSame('alice@example.com', $validated['email']);
    }

    #[Test]
    public function existsFailsWhenValueNotFoundInDb(): void
    {
        $this->expectException(ValidationException::class);
        $validator = new Validator();
        $validator->validate(
            ['email' => 'ghost@example.com'],
            ['email' => [new Exists('users', 'email')]],
        );
    }

    #[Test]
    public function existsPassesNullAndEmptyWithoutDbQuery(): void
    {
        $rule = new Exists('users', 'email');
        $this->assertNull($rule->validate(null, 'email'));
        $this->assertNull($rule->validate('', 'email'));
    }

    #[Test]
    public function existsErrorMessageContainsFieldName(): void
    {
        try {
            $validator = new Validator();
            $validator->validate(
                ['role_id' => '999'],
                ['role_id' => [new Exists('users', 'id')]],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_id', $e->errors);
        }
    }

    // ---- Unique ----

    #[Test]
    public function uniqueImplementsDatabaseRule(): void
    {
        $this->assertInstanceOf(DatabaseRule::class, new Unique('users', 'email'));
        $this->assertInstanceOf(Rule::class, new Unique('users', 'email'));
    }

    #[Test]
    public function uniquePassesWhenValueIsUniqueInDb(): void
    {
        $validator = new Validator();
        $validated = $validator->validate(
            ['email' => 'bob@example.com'],
            ['email' => [new Unique('users', 'email')]],
        );
        $this->assertSame('bob@example.com', $validated['email']);
    }

    #[Test]
    public function uniqueFailsWhenValueAlreadyExistsInDb(): void
    {
        $this->expectException(ValidationException::class);
        $validator = new Validator();
        $validator->validate(
            ['email' => 'alice@example.com'],
            ['email' => [new Unique('users', 'email')]],
        );
    }

    #[Test]
    public function uniquePassesWhenOnlyMatchIsIgnoredRecord(): void
    {
        $validator = new Validator();
        // alice@example.com already exists but id u1 is ignored (self-update)
        $validated = $validator->validate(
            ['email' => 'alice@example.com'],
            ['email' => [new Unique('users', 'email', 'u1')]],
        );
        $this->assertSame('alice@example.com', $validated['email']);
    }

    #[Test]
    public function uniqueFailsEvenWithIgnoreIdWhenAnotherRecordMatches(): void
    {
        // u1 already has name='Alice'. Add u3 also with name='Alice'.
        // Ignoring u1 still finds u3 → should fail.
        $this->db->insert('users', [
            'id'         => 'u3',
            'name'       => 'Alice',
            'email'      => 'alice2@example.com',
            'password'   => 'hashed',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->expectException(ValidationException::class);
        $validator = new Validator();
        // u1 has name=Alice, u3 also has name=Alice; ignoring u1 still finds u3
        $validator->validate(
            ['name' => 'Alice'],
            ['name' => [new Unique('users', 'name', 'u1')]],
        );
    }

    #[Test]
    public function uniquePassesNullAndEmptyWithoutDbQuery(): void
    {
        $rule = new Unique('users', 'email');
        $this->assertNull($rule->validate(null, 'email'));
        $this->assertNull($rule->validate('', 'email'));
    }
}
