<?php

declare(strict_types=1);

namespace Fw\Tests;

use Fw\Database\Connection;
use Fw\Core\RequestContext;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base TestCase for all framework tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear RequestContext to prevent leakage between tests
        RequestContext::clear();

        // Clear session and globals between tests
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
        ];
    }

    protected function tearDown(): void
    {
        $this->db = null;

        // Ensure cleanup
        RequestContext::clear();

        parent::tearDown();
    }

    /**
     * Create an in-memory SQLite database for testing.
     */
    protected function createDatabase(): Connection
    {
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        return $this->db;
    }

    /**
     * Run database migrations on the test database.
     */
    protected function runMigrations(): void
    {
        if ($this->db === null) {
            $this->createDatabase();
        }

        $this->db->query("
            CREATE TABLE users (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                remember_token VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME
            )
        ");

        $this->db->query("
            CREATE TABLE posts (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                content TEXT,
                published_at DATETIME,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    protected function get(string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = [];
        $_POST = [];
    }

    protected function post(string $uri, array $data = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = [];
        $_POST = $data;
    }

    protected function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $_SERVER[$key] = $value;
        }
        return $this;
    }

    protected function assertSessionHas(string $key, mixed $value = null): void
    {
        $this->assertArrayHasKey($key, $_SESSION, "Session does not contain key: $key");
        if ($value !== null) {
            $this->assertEquals($value, $_SESSION[$key]);
        }
    }

    protected function assertSessionMissing(string $key): void
    {
        $this->assertArrayNotHasKey($key, $_SESSION, "Session contains key: $key");
    }
}
