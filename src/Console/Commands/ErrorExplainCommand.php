<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

final class ErrorExplainCommand extends Command
{
    /** @var array<array{pattern: string, cause: string, fix: string}> */
    private const array PATTERNS = [
        [
            'pattern' => '/Mass assignment violation on (\w+)/i',
            'cause' => 'A field is not in the model\'s $fillable array.',
            'fix' => 'Add the field to $fillable in your model, or run: php fw add:field {1} <field> <type>',
        ],
        [
            'pattern' => '/Class ["\']?([^"\']+)["\']? not found/i',
            'cause' => 'The class does not exist or is not autoloaded.',
            'fix' => 'Create the class with: php fw make:controller or php fw make:model, then run: composer dump-autoload',
        ],
        [
            'pattern' => '/Call to undefined method.*unwrap\(\)/i',
            'cause' => 'unwrap() was removed in v3. Result/Option must use match() for exhaustive handling.',
            'fix' => 'Replace ->unwrap() with ->match(ok: fn($v) => ..., err: fn($e) => ...) or ->unwrapOr($default)',
        ],
        [
            'pattern' => '/Call to undefined method.*getValue\(\)/i',
            'cause' => 'getValue() was removed in v3. Use match() for exhaustive handling.',
            'fix' => 'Replace ->getValue() with ->match(ok: fn($v) => $v, err: fn($e) => ...)',
        ],
        [
            'pattern' => '/Call to undefined method.*getError\(\)/i',
            'cause' => 'getError() was removed in v3. Use match() for exhaustive handling.',
            'fix' => 'Replace ->getError() with ->match(ok: fn($v) => ..., err: fn($e) => $e)',
        ],
        [
            'pattern' => '/ValidationException/i',
            'cause' => 'Request validation failed. In v3, use FormRequest with typed Rule objects.',
            'fix' => 'Create a FormRequest: php fw make:request StorePostRequest, then use typed rules in rules()',
        ],
        [
            'pattern' => '/SQLSTATE\[23000\].*Integrity constraint/i',
            'cause' => 'A database constraint was violated (e.g., foreign key, unique, or NOT NULL).',
            'fix' => 'Check your foreign key references and ensure related records exist. Run: php fw db:status',
        ],
        [
            'pattern' => '/SQLSTATE\[42S02\].*Table.*doesn.t exist/i',
            'cause' => 'The database table has not been created.',
            'fix' => 'Run pending migrations: php fw migrate',
        ],
        [
            'pattern' => '/Call to undefined method.*validate\(\)/i',
            'cause' => 'Controller::validate() was removed in v3. Use FormRequest instead.',
            'fix' => 'Create a FormRequest with typed rules. Run: php fw make:request, then php fw fix',
        ],
        [
            'pattern' => '/Closure route handlers are not allowed/i',
            'cause' => 'v3 requires [Controller::class, \'method\'] syntax for all routes. Closures are banned.',
            'fix' => 'Run: php fw fix (auto-extracts closures into controllers). Or manually: php fw make:controller NameController then replace the closure with [NameController::class, \'method\'] in config/routes.php',
        ],
    ];

    protected string $name = 'error:explain';

    protected string $description = 'Parse an error message and suggest a fix';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('message', 'The error message to explain', true);
    }

    public function handle(): int
    {
        $message = $this->argument('message');
        if ($message === null || $message === '') {
            $this->error('Usage: php fw error:explain "<error message>"');
            return 1;
        }

        $this->newLine();

        foreach (self::PATTERNS as $entry) {
            if (preg_match($entry['pattern'], $message, $matches)) {
                $fix = $entry['fix'];
                // Replace {1}, {2}, etc. with captured groups
                foreach ($matches as $i => $match) {
                    if ($i > 0) {
                        $fix = str_replace('{' . $i . '}', $match, $fix);
                    }
                }

                $this->info('Error Explained');
                $this->line(str_repeat('─', 40));
                $this->newLine();
                $this->line("  Cause: {$entry['cause']}");
                $this->newLine();
                $this->line("  Fix:   {$fix}");
                $this->newLine();
                return 0;
            }
        }

        $this->comment('No known pattern matches this error.');
        $this->line('Try: php fw check to validate your project.');
        $this->newLine();

        return 0;
    }
}
