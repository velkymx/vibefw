<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\Migration\Blueprint;
use Fw\Database\Migration\Migration;
use Fw\Database\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private Connection $db;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Connection::reset();
        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->tempDir = sys_get_temp_dir() . '/fw_migration_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/database/migrations', 0o755, true);
    }

    protected function tearDown(): void
    {
        Connection::reset();
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    // ==========================================
    // ANONYMOUS CLASS RESOLUTION
    // ==========================================

    #[Test]
    public function migratorResolvesAnonymousClassMigration(): void
    {
        $migrationContent = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('test_items', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down(): void
            {
                $this->drop('test_items');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_test_items_table.php',
            $this->dedent($migrationContent)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $ran = $migrator->run();

        $this->assertCount(1, $ran);

        // Verify table was actually created
        $result = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='test_items'"
        );
        $this->assertNotNull($result);
    }

    #[Test]
    public function migratorRollsBackAnonymousClassMigration(): void
    {
        $migrationContent = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('rollback_test', function (Blueprint $table): void {
                    $table->id();
                    $table->string('title');
                });
            }

            public function down(): void
            {
                $this->drop('rollback_test');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_rollback_test_table.php',
            $this->dedent($migrationContent)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $migrator->run();

        // Verify table exists
        $result = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='rollback_test'"
        );
        $this->assertNotNull($result);

        // Rollback
        $rolledBack = $migrator->rollback();
        $this->assertCount(1, $rolledBack);

        // Verify table is gone
        $result = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='rollback_test'"
        );
        $this->assertNull($result);
    }

    #[Test]
    public function migratorRunsMultipleMigrationsInOrder(): void
    {
        $migration1 = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('authors', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                });
            }

            public function down(): void
            {
                $this->drop('authors');
            }
        };
        PHP;

        $migration2 = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('books', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('author_id')->constrained()->cascadeOnDelete();
                    $table->string('title');
                });
            }

            public function down(): void
            {
                $this->drop('books');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_authors_table.php',
            $this->dedent($migration1)
        );
        file_put_contents(
            $this->tempDir . '/database/migrations/0002_create_books_table.php',
            $this->dedent($migration2)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $ran = $migrator->run();

        $this->assertCount(2, $ran);

        // Verify both tables exist
        $authors = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='authors'"
        );
        $books = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='books'"
        );
        $this->assertNotNull($authors);
        $this->assertNotNull($books);
    }

    // ==========================================
    // MIGRATION CONVENIENCE METHODS
    // ==========================================

    #[Test]
    public function createMethodCreatesTable(): void
    {
        $migration = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('widgets', function (Blueprint $table): void {
                    $table->id();
                    $table->string('label');
                });
            }

            public function down(): void
            {
                $this->drop('widgets');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_widgets_table.php',
            $this->dedent($migration)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $migrator->run();

        $result = $this->db->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='widgets'"
        );
        $this->assertNotNull($result);
    }

    #[Test]
    public function tableMethodAltersExistingTable(): void
    {
        // First create the table
        $this->db->getPdo()->exec(
            'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255))'
        );

        $migration = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->table('products', function (Blueprint $table): void {
                    $table->string('sku', 100);
                    $table->integer('stock');
                });
            }

            public function down(): void
            {
                $this->table('products', function (Blueprint $table): void {
                    $table->dropColumn('sku');
                    $table->dropColumn('stock');
                });
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_add_sku_to_products_table.php',
            $this->dedent($migration)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $migrator->run();

        // Verify columns were added by inserting a row
        $this->db->getPdo()->exec(
            "INSERT INTO products (name, sku, stock) VALUES ('Widget', 'W-001', 42)"
        );
        $row = $this->db->selectOne('SELECT sku, stock FROM products WHERE name = ?', ['Widget']);

        $this->assertSame('W-001', $row['sku']);
        $this->assertSame(42, (int) $row['stock']);
    }

    // ==========================================
    // BLUEPRINT METHODS
    // ==========================================

    #[Test]
    public function blueprintDropColumnGeneratesAlterSql(): void
    {
        $blueprint = new Blueprint('users', 'sqlite');
        $blueprint->dropColumn('avatar');

        $sql = $blueprint->toAlterSql();

        $this->assertStringContainsString('DROP COLUMN', $sql);
        $this->assertStringContainsString('avatar', $sql);
    }

    #[Test]
    public function blueprintConstrainedAddsForeignKey(): void
    {
        $blueprint = new Blueprint('posts', 'sqlite');
        $blueprint->id();
        $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
        $blueprint->string('title');

        $sql = $blueprint->toSql();

        $this->assertStringContainsString('FOREIGN KEY', $sql);
        $this->assertStringContainsString('REFERENCES', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('CASCADE', $sql);
    }

    #[Test]
    public function blueprintConstrainedInfersTableFromColumnName(): void
    {
        $blueprint = new Blueprint('comments', 'sqlite');
        $blueprint->foreignId('post_id')->constrained();

        $sql = $blueprint->toSql();

        $this->assertStringContainsString('REFERENCES', $sql);
        $this->assertStringContainsString('posts', $sql);
    }

    #[Test]
    public function blueprintDecimalColumn(): void
    {
        $blueprint = new Blueprint('products', 'sqlite');
        $blueprint->id();
        $blueprint->decimal('price', 10, 2);

        $sql = $blueprint->toSql();

        $this->assertStringContainsString('DECIMAL(10, 2)', $sql);
    }

    #[Test]
    public function blueprintJsonColumn(): void
    {
        $blueprint = new Blueprint('settings', 'sqlite');
        $blueprint->id();
        $blueprint->json('data');

        $sql = $blueprint->toSql();

        $this->assertStringContainsString('data', $sql);
        $this->assertStringContainsString('TEXT', $sql);
    }

    #[Test]
    public function blueprintDateColumn(): void
    {
        $blueprint = new Blueprint('events', 'sqlite');
        $blueprint->id();
        $blueprint->date('event_date');

        $sql = $blueprint->toSql();

        $this->assertStringContainsString('DATE', $sql);
    }

    // ==========================================
    // MIGRATOR STATUS
    // ==========================================

    #[Test]
    public function migratorReportsCorrectStatus(): void
    {
        $migration = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('status_test', function (Blueprint $table): void {
                    $table->id();
                });
            }

            public function down(): void
            {
                $this->drop('status_test');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_status_test_table.php',
            $this->dedent($migration)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');

        // Before running
        $status = $migrator->status();
        $this->assertCount(1, $status);
        $this->assertSame('Pending', $status[0]['status']);

        // After running
        $migrator->run();
        $status = $migrator->status();
        $this->assertSame('Ran', $status[0]['status']);
    }

    #[Test]
    public function migratorSkipsAlreadyRanMigrations(): void
    {
        $migration = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                $this->create('skip_test', function (Blueprint $table): void {
                    $table->id();
                });
            }

            public function down(): void
            {
                $this->drop('skip_test');
            }
        };
        PHP;

        file_put_contents(
            $this->tempDir . '/database/migrations/0001_create_skip_test_table.php',
            $this->dedent($migration)
        );

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');

        $ran1 = $migrator->run();
        $this->assertCount(1, $ran1);

        $ran2 = $migrator->run();
        $this->assertCount(0, $ran2);
    }

    // ==========================================
    // SPA MIGRATION STUBS
    // ==========================================

    #[Test]
    public function spaMigrationStubsReturnAnonymousClassInstances(): void
    {
        $stubDir = __DIR__ . '/../../stubs/spa/database/migrations';
        $stubs = glob($stubDir . '/*.php.stub');

        $this->assertNotEmpty($stubs, 'No SPA migration stubs found');

        foreach ($stubs as $stub) {
            $name = basename($stub, '.php.stub');
            // Copy stub to temp dir as .php
            $target = $this->tempDir . '/database/migrations/' . $name . '.php';
            copy($stub, $target);

            $result = require $target;
            $this->assertInstanceOf(
                Migration::class,
                $result,
                "Stub {$name} must return an anonymous Migration instance"
            );
        }
    }

    #[Test]
    public function spaMigrationStubsRunFullSequence(): void
    {
        $stubDir = __DIR__ . '/../../stubs/spa/database/migrations';
        $stubs = glob($stubDir . '/*.php.stub');

        foreach ($stubs as $stub) {
            $name = basename($stub, '.php.stub');
            $target = $this->tempDir . '/database/migrations/' . $name . '.php';
            copy($stub, $target);
        }

        $migrator = new Migrator($this->db, $this->tempDir . '/database/migrations');
        $ran = $migrator->run();

        $this->assertCount(count($stubs), $ran, 'All SPA migration stubs should run');

        // Verify core tables exist
        $expectedTables = ['users', 'jobs', 'password_resets', 'personal_access_tokens', 'email_verifications'];
        foreach ($expectedTables as $table) {
            $result = $this->db->selectOne(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$table]
            );
            $this->assertNotNull($result, "Table '{$table}' should exist after migrations");
        }

        // Verify users table has remember_token column (from 0004 alter migration)
        $this->db->getPdo()->exec(
            "INSERT INTO users (name, email, password, remember_token) VALUES ('Test', 'test@test.com', 'hash', 'tok')"
        );
        $row = $this->db->selectOne("SELECT remember_token FROM users WHERE email = 'test@test.com'");
        $this->assertSame('tok', $row['remember_token']);

        // Verify rollback works
        $rolledBack = $migrator->reset();
        $this->assertCount(count($stubs), $rolledBack, 'All migrations should roll back');
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function dedent(string $content): string
    {
        $lines = explode("\n", $content);
        $minIndent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $minIndent = min($minIndent, $indent);
        }
        if ($minIndent === 0 || $minIndent === PHP_INT_MAX) {
            return $content;
        }
        return implode("\n", array_map(
            fn(string $line) => trim($line) === '' ? '' : substr($line, $minIndent),
            $lines
        ));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
