<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MakeLinkCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir, Output::createBuffer());

        $this->writeModelFile('Post', 'posts');
        $this->writeModelFile('Comment', 'comments');
        $this->writeModelFile('Tag', 'tags');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function hasManyCreatesForeignKeyMigration(): void
    {
        $exitCode = $this->console->run(['fw', 'make:link', 'Post', 'Comment', '--hasMany']);

        $this->assertSame(0, $exitCode);

        $migrations = glob($this->tempDir . '/database/migrations/*_add_post_id_to_comments_table.php');
        $this->assertNotEmpty($migrations);

        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('post_id', $content);
        $this->assertStringContainsString('foreignId', $content);
    }

    #[Test]
    public function hasManyUpdatesParentModel(): void
    {
        $this->console->run(['fw', 'make:link', 'Post', 'Comment', '--hasMany']);

        $content = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString('function comments()', $content);
        $this->assertStringContainsString('hasMany', $content);
        $this->assertStringContainsString('Comment::class', $content);
    }

    #[Test]
    public function hasManyUpdatesChildModel(): void
    {
        $this->console->run(['fw', 'make:link', 'Post', 'Comment', '--hasMany']);

        $content = file_get_contents($this->tempDir . '/app/Models/Comment.php');
        $this->assertStringContainsString('function post()', $content);
        $this->assertStringContainsString('belongsTo', $content);
        $this->assertStringContainsString('Post::class', $content);
    }

    #[Test]
    public function hasOneCreatesForeignKeyMigration(): void
    {
        $this->writeModelFile('Profile', 'profiles');

        $exitCode = $this->console->run(['fw', 'make:link', 'Post', 'Profile', '--hasOne']);

        $this->assertSame(0, $exitCode);

        $migrations = glob($this->tempDir . '/database/migrations/*_add_post_id_to_profiles_table.php');
        $this->assertNotEmpty($migrations);
    }

    #[Test]
    public function manyToManyCreatesPivotMigration(): void
    {
        $exitCode = $this->console->run(['fw', 'make:link', 'Post', 'Tag', '--manyToMany']);

        $this->assertSame(0, $exitCode);

        $migrations = glob($this->tempDir . '/database/migrations/*_create_post_tag_table.php');
        $this->assertNotEmpty($migrations);

        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('post_id', $content);
        $this->assertStringContainsString('tag_id', $content);
    }

    #[Test]
    public function manyToManyUpdatesBothModels(): void
    {
        $this->console->run(['fw', 'make:link', 'Post', 'Tag', '--manyToMany']);

        $postContent = file_get_contents($this->tempDir . '/app/Models/Post.php');
        $this->assertStringContainsString('function tags()', $postContent);
        $this->assertStringContainsString('belongsToMany', $postContent);

        $tagContent = file_get_contents($this->tempDir . '/app/Models/Tag.php');
        $this->assertStringContainsString('function posts()', $tagContent);
        $this->assertStringContainsString('belongsToMany', $tagContent);
    }

    #[Test]
    public function failsWithoutRelationshipFlag(): void
    {
        $exitCode = $this->console->run(['fw', 'make:link', 'Post', 'Comment']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsIfModelNotFound(): void
    {
        $exitCode = $this->console->run(['fw', 'make:link', 'Post', 'Missing', '--hasMany']);

        $this->assertSame(1, $exitCode);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function writeModelFile(string $name, string $table): void
    {
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;

class {$name} extends Model
{
    protected static ?string \$table = '{$table}';

    protected static array \$fillable = [
        'name',
    ];

    protected static array \$casts = [
    ];
}
PHP;

        file_put_contents($this->tempDir . '/app/Models/' . $name . '.php', $content . "\n");
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
