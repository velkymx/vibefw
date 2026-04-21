<?php

declare(strict_types=1);

namespace Fw\Console;

use RuntimeException;

/**
 * Console output with color support.
 */
final class Output
{
    private const COLORS = [
        'reset' => "\033[0m",
        'black' => "\033[30m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'bg_red' => "\033[41m",
        'bg_green' => "\033[42m",
        'bg_yellow' => "\033[43m",
        'bg_blue' => "\033[44m",
    ];

    private bool $supportsColor;

    /** @var resource */
    private mixed $stream;

    /**
     * @param resource|null $stream Writable stream (defaults to STDOUT)
     */
    public function __construct(mixed $stream = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->supportsColor = $this->detectColorSupport();
    }

    /**
     * Create an Output instance that writes to an in-memory buffer.
     */
    public static function createBuffer(): self
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open in-memory buffer');
        }
        return new self($stream);
    }

    /**
     * Get buffered output contents (for Output instances created with createBuffer).
     */
    public function getBuffer(): string
    {
        $position = ftell($this->stream);
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);
        if ($position !== false) {
            fseek($this->stream, $position);
        }

        return is_string($contents) ? $contents : '';
    }

    public function line(string $text): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->color($text, 'blue'));
    }

    public function success(string $text): void
    {
        $this->line($this->color('✓ ' . $text, 'green'));
    }

    public function warning(string $text): void
    {
        $this->line($this->color('⚠ ' . $text, 'yellow'));
    }

    public function error(string $text): void
    {
        $this->line($this->color('✗ ' . $text, 'red'));
    }

    public function comment(string $text): void
    {
        $this->line($this->color($text, 'gray'));
    }

    public function newLine(int $count = 1): void
    {
        fwrite($this->stream, str_repeat(PHP_EOL, $count));
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
    }

    /**
     * Output a formatted table.
     *
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        if (empty($headers) && empty($rows)) {
            return;
        }

        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strlen($header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        // Build separator line
        $separator = '+';
        foreach ($widths as $width) {
            $separator .= str_repeat('-', $width + 2) . '+';
        }

        // Output table
        $this->line($separator);

        // Headers
        $headerLine = '|';
        foreach ($headers as $i => $header) {
            $headerLine .= ' ' . str_pad($header, $widths[$i]) . ' |';
        }
        $this->line($this->color($headerLine, 'bold'));
        $this->line($separator);

        // Rows
        foreach ($rows as $row) {
            $rowLine = '|';
            foreach ($widths as $i => $width) {
                $cell = $row[$i] ?? '';
                $rowLine .= ' ' . str_pad((string) $cell, $width) . ' |';
            }
            $this->line($rowLine);
        }

        $this->line($separator);
    }

    /**
     * Output a definition list.
     *
     * @param array<string, string> $items
     */
    public function listing(array $items): void
    {
        $maxKeyLen = 0;
        foreach (array_keys($items) as $key) {
            $maxKeyLen = max($maxKeyLen, mb_strlen($key));
        }

        foreach ($items as $key => $value) {
            $this->line(
                '  ' . $this->color(str_pad($key, $maxKeyLen), 'green') . '  ' . $value
            );
        }
    }

    /**
     * Apply color to text.
     */
    public function color(string $text, string $color): string
    {
        if (! $this->supportsColor) {
            return $text;
        }

        $code = self::COLORS[$color] ?? '';
        if ($code === '') {
            return $text;
        }

        return $code . $text . self::COLORS['reset'];
    }

    /**
     * Detect if the terminal supports colors.
     */
    private function detectColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('TERM') === 'dumb') {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || str_contains(getenv('TERM') ?: '', 'xterm');
        }

        if ($this->stream !== STDOUT) {
            return false;
        }

        return stream_isatty(STDOUT);
    }
}
