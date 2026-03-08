<?php

declare(strict_types=1);

namespace Fw\Security;

final class Sanitizer
{
    /**
     * Unicode characters used for text direction/formatting attacks.
     * These can be used to visually hide malicious content.
     */
    private const array UNICODE_CONTROL_CHARS = [
        "\u{200B}", // Zero Width Space
        "\u{200C}", // Zero Width Non-Joiner
        "\u{200D}", // Zero Width Joiner
        "\u{200E}", // Left-to-Right Mark
        "\u{200F}", // Right-to-Left Mark
        "\u{202A}", // Left-to-Right Embedding
        "\u{202B}", // Right-to-Left Embedding
        "\u{202C}", // Pop Directional Formatting
        "\u{202D}", // Left-to-Right Override
        "\u{202E}", // Right-to-Left Override (used in URL obfuscation)
        "\u{2060}", // Word Joiner
        "\u{2061}", // Function Application
        "\u{2062}", // Invisible Times
        "\u{2063}", // Invisible Separator
        "\u{2064}", // Invisible Plus
        "\u{FEFF}", // Zero Width No-Break Space (BOM)
    ];

    /**
     * Dangerous URL schemes that must never be allowed.
     * @var list<string>
     */
    private const array DANGEROUS_SCHEMES = [
        'javascript',
        'vbscript',
        'data',
        'file',
        'blob',
    ];

    public static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip all HTML tags. No allowlist — strip_tags with allowed tags
     * is insecure because it passes through tag attributes unchanged
     * (e.g. <a href="javascript:..."> survives with <a> allowed).
     * If you need safe HTML, use an HTML purifier library.
     */
    public static function stripTags(string $value): string
    {
        return strip_tags($value);
    }

    public static function alphanumeric(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $value) ?? '';
    }

    public static function alpha(string $value): string
    {
        return preg_replace('/[^a-zA-Z]/', '', $value) ?? '';
    }

    public static function numeric(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    public static function slug(string $value): string
    {
        $value = preg_replace('/[^\w\s-]/', '', strtolower(trim($value))) ?? '';
        return preg_replace('/[\s_]+/', '-', $value) ?? '';
    }

    public static function filename(string $value): string
    {
        $value = basename($value);
        $value = preg_replace('/[^\w\s.\-]/', '', $value) ?? '';
        $value = preg_replace('/\.{2,}/', '.', $value) ?? '';
        return trim($value, '. ');
    }

    /**
     * Sanitize and validate a filesystem path.
     *
     * Returns the resolved real path if it exists, null otherwise.
     *
     * @param string|null $basePath Optional base directory to restrict paths to
     */
    public static function path(string $value, ?string $basePath = null): ?string
    {
        if (preg_match('/\.\.|[<>:"|?*\x00]/', $value)) {
            return null;
        }

        $realPath = realpath($value);
        if ($realPath === false) {
            return null;
        }

        if ($basePath !== null) {
            $realBasePath = realpath($basePath);
            if ($realBasePath === false) {
                return null;
            }
            if (!str_starts_with($realPath, $realBasePath . DIRECTORY_SEPARATOR)
                && $realPath !== $realBasePath) {
                return null;
            }
        }

        return $realPath;
    }

    /**
     * Sanitize email address.
     */
    public static function email(string $value): string
    {
        $value = trim(strtolower($value));
        $value = preg_replace('/[^a-z0-9.!#$%&\'*+\/=?^_`{|}~@-]/i', '', $value) ?? '';
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    /**
     * Sanitize URL. Only allows http/https.
     */
    public static function url(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\s]/', '', trim($value)) ?? '';
        $value = str_replace(self::UNICODE_CONTROL_CHARS, '', $value);

        $lower = mb_strtolower($value);
        foreach (self::DANGEROUS_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme . ':')) {
                return '';
            }
        }

        // Block protocol-relative URLs (//attacker.com/evil)
        if (str_starts_with($value, '//')) {
            return '';
        }

        if (!preg_match('/^https?:\/\//i', $value)) {
            return '';
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parsed = parse_url($value);
        if ($parsed === false || !isset($parsed['scheme'])) {
            return '';
        }

        $scheme = mb_strtolower($parsed['scheme']);
        return ($scheme === 'http' || $scheme === 'https') ? $value : '';
    }

    /**
     * Sanitize to integer.
     *
     * Strips non-numeric characters (preserving a leading minus) and casts.
     */
    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        return (int) preg_replace('/[^\d-]/', '', (string) $value);
    }

    /**
     * Sanitize to float.
     *
     * Strips non-numeric characters, preserving a leading minus and one decimal point.
     */
    public static function float(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $str = (string) $value;

        // Use filter_var for proper validation first
        $filtered = filter_var($str, FILTER_VALIDATE_FLOAT);
        if ($filtered !== false) {
            return $filtered;
        }

        // Fallback: strip non-numeric chars and try to extract a valid float
        $cleaned = preg_replace('/[^\d.\-]/', '', $str) ?? '';
        if ($cleaned !== '' && preg_match('/^-?\d+(?:\.\d+)?$/', $cleaned)) {
            return (float) $cleaned;
        }

        return 0.0;
    }

    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Collapse runs of whitespace into a single space and trim the result.
     */
    public static function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Decode a JSON string, returning null on any error.
     *
     * @param int $maxDepth  Nesting depth limit (guards against billion-laughs payloads)
     * @param int $flags     Any json_decode flags (e.g. JSON_BIGINT_AS_STRING)
     */
    public static function json(string $value, int $maxDepth = 32, int $flags = 0): mixed
    {
        $decoded = json_decode($value, true, $maxDepth, $flags);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public static function array(array $data, array $rules): array
    {
        $result = [];

        foreach ($rules as $key => $sanitizer) {
            if (!isset($data[$key])) {
                continue;
            }

            $value = $data[$key];

            if (is_callable($sanitizer)) {
                $result[$key] = $sanitizer($value);
            } elseif (is_string($sanitizer) && method_exists(self::class, $sanitizer)) {
                $result[$key] = self::$sanitizer($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
