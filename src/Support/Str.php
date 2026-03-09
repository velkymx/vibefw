<?php

declare(strict_types=1);

namespace Fw\Support;

use Countable;

/**
 * String helper utilities.
 *
 * Provides fluent, expressive methods for working with strings,
 * including case conversion, pattern matching, manipulation, and more.
 */
final class Str
{
    /**
     * The cache of studly-cased words.
     *
     * @var array<string, string>
     */
    private static array $studlyCache = [];

    /**
     * The cache of camel-cased words.
     *
     * @var array<string, string>
     */
    private static array $camelCache = [];

    /**
     * The cache of snake-cased words.
     *
     * @var array<string, string>
     */
    private static array $snakeCache = [];

    /**
     * Get a new Stringable object from the given string.
     */
    public static function of(string $string): Stringable
    {
        return new Stringable($string);
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     */
    public static function after(string $subject, string $search): string
    {
        return $search === '' ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strrpos($subject, $search);

        if ($position === false) {
            return $subject;
        }

        return mb_substr($subject, $position + mb_strlen($search));
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     */
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $result = strstr($subject, $search, true);

        return $result === false ? $subject : $result;
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strrpos($subject, $search);

        if ($position === false) {
            return $subject;
        }

        return mb_substr($subject, 0, $position);
    }

    /**
     * Get the portion of a string between two given values.
     */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return self::beforeLast(self::after($subject, $from), $to);
    }

    /**
     * Get the smallest possible portion of a string between two given values.
     */
    public static function betweenFirst(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return self::before(self::after($subject, $from), $to);
    }

    /**
     * Convert a value to camelCase.
     */
    public static function camel(string $value): string
    {
        if (isset(self::$camelCache[$value])) {
            return self::$camelCache[$value];
        }

        if (count(self::$camelCache) >= 512) {
            self::$camelCache = array_slice(self::$camelCache, 256, null, true);
        }

        return self::$camelCache[$value] = lcfirst(self::studly($value));
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public static function contains(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }

        if (!is_iterable($needles)) {
            $needles = [$needles];
        }

        foreach ($needles as $needle) {
            if ($ignoreCase) {
                $needle = mb_strtolower($needle);
            }

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param iterable<string> $needles
     */
    public static function containsAll(string $haystack, iterable $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (!self::contains($haystack, $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if a given string ends with a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public static function endsWith(string $haystack, string|iterable $needles): bool
    {
        if (!is_iterable($needles)) {
            $needles = [$needles];
        }

        foreach ($needles as $needle) {
            if ((string) $needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts an excerpt from text that matches the first instance of a phrase.
     */
    public static function excerpt(string $text, string $phrase = '', array $options = []): ?string
    {
        $radius = $options['radius'] ?? 100;
        $omission = $options['omission'] ?? '...';

        preg_match('/^(.*?)(' . preg_quote($phrase, '/') . ')(.*)$/iu', $text, $matches);

        if (empty($matches)) {
            return null;
        }

        $start = ltrim($matches[1]);
        $start = self::of($start)->words((int) ($radius / 10), '')->value();

        $end = rtrim($matches[3]);
        $end = self::of($end)->words((int) ($radius / 10), '')->value();

        $start = mb_strlen($start) < mb_strlen($matches[1]) ? $omission . $start : $start;
        $end = mb_strlen($end) < mb_strlen($matches[3]) ? $end . $omission : $end;

        return $start . $matches[2] . $end;
    }

    /**
     * Cap a string with a single instance of a given value.
     */
    public static function finish(string $value, string $cap): string
    {
        $quoted = preg_quote($cap, '/');

        return preg_replace('/(?:' . $quoted . ')+$/u', '', $value) . $cap;
    }

    /**
     * Wrap the string with the given strings.
     */
    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param string|iterable<string> $pattern
     */
    public static function is(string|iterable $pattern, string $value): bool
    {
        if (!is_iterable($pattern)) {
            $pattern = [$pattern];
        }

        foreach ($pattern as $p) {
            $p = (string) $p;

            if ($p === $value) {
                return true;
            }

            $p = preg_quote($p, '#');
            $p = str_replace('\*', '.*', $p);

            if (preg_match('#^' . $p . '\z#u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string is 7-bit ASCII.
     */
    public static function isAscii(string $value): bool
    {
        return mb_check_encoding($value, 'ASCII');
    }

    /**
     * Determine if a given value is valid JSON.
     */
    public static function isJson(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Determine if a given value is a valid UUID.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match('/^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/D', $value) === 1;
    }

    /**
     * Determine if a given value is a valid ULID.
     */
    public static function isUlid(string $value): bool
    {
        if (strlen($value) !== 26) {
            return false;
        }

        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', strtoupper($value)) === 1;
    }

    /**
     * Convert a string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * Return the length of the given string.
     */
    public static function length(string $value, ?string $encoding = null): int
    {
        return mb_strlen($value, $encoding);
    }

    /**
     * Limit the number of characters in a string.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    /**
     * Convert the given string to lower-case.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Limit the number of words in a string.
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        if (!isset($matches[0]) || self::length($value) === self::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Masks a portion of a string with a repeated character.
     */
    public static function mask(string $string, string $character, int $index, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        if ($character === '') {
            return $string;
        }

        $segment = mb_substr($string, $index, $length, $encoding);

        if ($segment === '') {
            return $string;
        }

        $strlen = mb_strlen($string, $encoding);
        $startIndex = $index;

        if ($index < 0) {
            $startIndex = $index < -$strlen ? 0 : $strlen + $index;
        }

        $start = mb_substr($string, 0, $startIndex, $encoding);
        $segmentLen = mb_strlen($segment, $encoding);
        $end = mb_substr($string, $startIndex + $segmentLen);

        return $start . str_repeat(mb_substr($character, 0, 1, $encoding), $segmentLen) . $end;
    }

    /**
     * Pad both sides of a string with another.
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));
        $shortLeft = (int) floor($short / 2);
        $shortRight = (int) ceil($short / 2);

        return mb_substr(str_repeat($pad, $shortLeft), 0, $shortLeft) .
            $value .
            mb_substr(str_repeat($pad, $shortRight), 0, $shortRight);
    }

    /**
     * Pad the left side of a string with another.
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));

        return mb_substr(str_repeat($pad, $short), 0, $short) . $value;
    }

    /**
     * Pad the right side of a string with another.
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));

        return $value . mb_substr(str_repeat($pad, $short), 0, $short);
    }

    /**
     * Generate a more truly "random" alpha-numeric string.
     */
    public static function random(int $length = 16): string
    {
        $string = '';

        while (($len = strlen($string)) < $length) {
            $size = $length - $len;
            $bytesSize = (int) ceil($size / 3) * 3;
            $bytes = random_bytes($bytesSize);
            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }

        return $string;
    }

    /**
     * Repeat the given string.
     */
    public static function repeat(string $string, int $times): string
    {
        return str_repeat($string, $times);
    }

    /**
     * Replace the first occurrence of a given value in the string.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace the last occurrence of a given value in the string.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $position = strrpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace the given value in the given string.
     *
     * @param string|iterable<string> $search
     * @param string|iterable<string> $replace
     */
    public static function replace(string|iterable $search, string|iterable $replace, string $subject, bool $caseSensitive = true): string
    {
        if ($caseSensitive) {
            return str_replace($search, $replace, $subject);
        }

        return str_ireplace($search, $replace, $subject);
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param array<int, string> $replace
     */
    public static function replaceArray(string $search, array $replace, string $subject): string
    {
        foreach ($replace as $value) {
            $subject = self::replaceFirst($search, (string) $value, $subject);
        }

        return $subject;
    }

    /**
     * Reverse the given string.
     */
    public static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }

    /**
     * Begin a string with a single instance of a given value.
     */
    public static function start(string $value, string $prefix): string
    {
        $quoted = preg_quote($prefix, '/');

        return $prefix . preg_replace('/^(?:' . $quoted . ')+/u', '', $value);
    }

    /**
     * Convert the given string to upper-case.
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert the given string to title case.
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Convert the given string to title case for each word.
     */
    public static function headline(string $value): string
    {
        $parts = explode(' ', $value);
        $parts = count($parts) > 1
            ? array_map([self::class, 'title'], $parts)
            : array_map([self::class, 'title'], self::ucsplit(implode('_', $parts)));

        $collapsed = self::replace(['-', '_', ' '], '_', implode('_', $parts));

        return implode(' ', array_filter(explode('_', $collapsed)));
    }

    /**
     * Generate a URL-friendly "slug" from a given string.
     */
    public static function slug(string $title, string $separator = '-', ?string $language = 'en', array $dictionary = ['@' => 'at']): string
    {
        $title = $language ? self::ascii($title, $language) : $title;

        $flip = $separator === '-' ? '_' : '-';

        $title = preg_replace('![' . preg_quote($flip, '!') . ']+!u', $separator, $title);

        foreach ($dictionary as $key => $value) {
            $dictionary[$key] = $separator . $value . $separator;
        }

        $title = str_replace(array_keys($dictionary), array_values($dictionary), $title);

        $title = preg_replace('![^' . preg_quote($separator, '!') . '\pL\pN\s]+!u', '', self::lower($title));

        $title = preg_replace('![' . preg_quote($separator, '!') . '\s]+!u', $separator, $title);

        return trim($title, $separator);
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value;

        if (isset(self::$snakeCache[$key][$delimiter])) {
            return self::$snakeCache[$key][$delimiter];
        }

        // Count total cached entries across all delimiters, not just top-level keys.
        // Each word can have multiple entries (one per delimiter), so a simple
        // count() on the outer array would undercount and allow unbounded growth.
        if (array_sum(array_map('count', self::$snakeCache)) >= 512) {
            self::$snakeCache = array_slice(self::$snakeCache, 256, null, true);
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));
            $value = self::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
        }

        return self::$snakeCache[$key][$delimiter] = $value;
    }

    /**
     * Remove all whitespace from both ends of a string.
     */
    public static function squish(string $value): string
    {
        return preg_replace('~(\s|\x{3164}|\x{1160})+~u', ' ', preg_replace('~^[\s\x{FEFF}]+|[\s\x{FEFF}]+$~u', '', $value));
    }

    /**
     * Determine if a given string starts with a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public static function startsWith(string $haystack, string|iterable $needles): bool
    {
        if (!is_iterable($needles)) {
            $needles = [$needles];
        }

        foreach ($needles as $needle) {
            if ((string) $needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a value to StudlyCase.
     */
    public static function studly(string $value): string
    {
        $key = $value;

        if (isset(self::$studlyCache[$key])) {
            return self::$studlyCache[$key];
        }

        if (count(self::$studlyCache) >= 512) {
            self::$studlyCache = array_slice(self::$studlyCache, 256, null, true);
        }

        $words = explode(' ', self::replace(['-', '_'], ' ', $value));

        $studlyWords = array_map(fn ($word) => self::ucfirst($word), $words);

        return self::$studlyCache[$key] = implode('', $studlyWords);
    }

    /**
     * Returns the portion of the string specified by the start and length parameters.
     */
    public static function substr(string $string, int $start, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        return mb_substr($string, $start, $length, $encoding);
    }

    /**
     * Returns the number of substring occurrences.
     */
    public static function substrCount(string $haystack, string $needle, int $offset = 0, ?int $length = null): int
    {
        if ($length !== null) {
            return substr_count($haystack, $needle, $offset, $length);
        }

        return substr_count($haystack, $needle, $offset);
    }

    /**
     * Replace text within a portion of a string.
     */
    public static function substrReplace(string $string, string $replace, int $offset = 0, ?int $length = null): string
    {
        if ($length === null) {
            return substr_replace($string, $replace, $offset);
        }

        return substr_replace($string, $replace, $offset, $length);
    }

    /**
     * Swap multiple keywords in a string with other keywords.
     *
     * @param array<string, string> $map
     */
    public static function swap(array $map, string $subject): string
    {
        return strtr($subject, $map);
    }

    /**
     * Take the first or last {$limit} characters.
     */
    public static function take(string $string, int $limit): string
    {
        if ($limit < 0) {
            return self::substr($string, $limit);
        }

        return self::substr($string, 0, $limit);
    }

    /**
     * Trim the string of the given characters.
     */
    public static function trim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            return preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value) ?? trim($value);
        }

        return trim($value, $charlist);
    }

    /**
     * Left trim the string of the given characters.
     */
    public static function ltrim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            return preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+~u', '', $value) ?? ltrim($value);
        }

        return ltrim($value, $charlist);
    }

    /**
     * Right trim the string of the given characters.
     */
    public static function rtrim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            return preg_replace('~[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value) ?? rtrim($value);
        }

        return rtrim($value, $charlist);
    }

    /**
     * Make a string's first character lowercase.
     */
    public static function lcfirst(string $string): string
    {
        return self::lower(self::substr($string, 0, 1)) . self::substr($string, 1);
    }

    /**
     * Make a string's first character uppercase.
     */
    public static function ucfirst(string $string): string
    {
        return self::upper(self::substr($string, 0, 1)) . self::substr($string, 1);
    }

    /**
     * Split a string into pieces by uppercase characters.
     *
     * @return array<int, string>
     */
    public static function ucsplit(string $string): array
    {
        return preg_split('/(?=\p{Lu})/u', $string, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Get the number of words a string contains.
     */
    public static function wordCount(string $string, ?string $characters = null): int
    {
        return str_word_count($string, 0, $characters);
    }

    /**
     * Wrap a string to a given number of characters.
     */
    public static function wordWrap(string $string, int $characters = 75, string $break = "\n", bool $cutLongWords = false): string
    {
        return wordwrap($string, $characters, $break, $cutLongWords);
    }

    /**
     * Generate a UUID (version 4).
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate a ULID.
     */
    public static function ulid(): string
    {
        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $time = (int) (microtime(true) * 1000);
        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod = $time % 32;
            $timeChars = $encoding[$mod] . $timeChars;
            $time = ($time - $mod) / 32;
        }

        // Read all 80 bits from 10 random bytes using a bit-stream approach.
        // The naive `ord($bytes[$i % 10]) % 32` only uses 5 low bits per byte
        // and repeats bytes 0-5 for chars 10-15, giving just 50 bits of entropy.
        // This correctly consumes every bit: 10 bytes × 8 = 80 bits → 16 chars × 5 bits.
        $randomChars = '';
        $randomBytes = random_bytes(10);
        $bits = 0;
        $bitCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $bits = ($bits << 8) | ord($randomBytes[$i]);
            $bitCount += 8;
            while ($bitCount >= 5) {
                $bitCount -= 5;
                $randomChars .= $encoding[($bits >> $bitCount) & 31];
            }
        }

        return $timeChars . $randomChars;
    }

    /**
     * Transliterate a UTF-8 value to ASCII.
     */
    public static function ascii(string $value, string $language = 'en'): string
    {
        if (function_exists('transliterator_transliterate')) {
            return transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $value) ?: $value;
        }

        // Fallback for when intl extension is not available
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            return preg_replace('/[^\x00-\x7F]/u', '', $value) ?: $value;
        }

        return $ascii;
    }

    /**
     * Determine if a given string is empty after trimming.
     */
    public static function isEmpty(?string $value): bool
    {
        return $value === null || $value === '' || self::trim($value) === '';
    }

    /**
     * Determine if a given string is not empty after trimming.
     */
    public static function isNotEmpty(?string $value): bool
    {
        return !self::isEmpty($value);
    }

    /**
     * Parse a Class[@]method style callback into class and method.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function parseCallback(string $callback, ?string $default = null): array
    {
        if (self::contains($callback, '@')) {
            return explode('@', $callback, 2);
        }

        return [$callback, $default];
    }

    /**
     * Get the plural form of an English word.
     */
    public static function plural(string $value, int|array|Countable $count = 2): string
    {
        if (is_countable($count)) {
            $count = count($count);
        }

        if (abs($count) === 1) {
            return $value;
        }

        $plural = self::pluralize($value);

        return $plural ?: $value;
    }

    /**
     * Get the singular form of an English word.
     */
    public static function singular(string $value): string
    {
        $singular = self::singularize($value);

        return $singular ?: $value;
    }

    /**
     * Clear the case conversion caches.
     */
    public static function flushCache(): void
    {
        self::$studlyCache = [];
        self::$camelCache = [];
        self::$snakeCache = [];
    }

    /**
     * Simple English pluralization rules.
     */
    private static function pluralize(string $value): string
    {
        $lower = self::lower($value);

        $irregulars = [
            'child' => 'children', 'goose' => 'geese', 'man' => 'men', 'woman' => 'women',
            'tooth' => 'teeth', 'foot' => 'feet', 'mouse' => 'mice', 'person' => 'people',
        ];

        if (isset($irregulars[$lower])) {
            return $irregulars[$lower];
        }

        $rules = [
            '/(quiz)$/i' => '$1zes',
            '/^(ox)$/i' => '$1en',
            '/([m|l])ouse$/i' => '$1ice',
            '/(matr|vert|ind)ix|ex$/i' => '$1ices',
            '/(x|ch|ss|sh)$/i' => '$1es',
            '/([^aeiouy]|qu)y$/i' => '$1ies',
            '/(hive)$/i' => '$1s',
            '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '$1a',
            '/(buffal|tomat)o$/i' => '$1oes',
            '/(bu)s$/i' => '$1ses',
            '/(alias|status)$/i' => '$1es',
            '/(octop|vir)us$/i' => '$1i',
            '/(ax|test)is$/i' => '$1es',
            '/s$/i' => 's',
            '/$/' => 's',
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value);
            }
        }

        return $value . 's';
    }

    /**
     * Simple English singularization rules.
     */
    private static function singularize(string $value): string
    {
        $lower = self::lower($value);

        $irregulars = [
            'children' => 'child', 'geese' => 'goose', 'men' => 'man', 'women' => 'woman',
            'teeth' => 'tooth', 'feet' => 'foot', 'mice' => 'mouse', 'people' => 'person',
        ];

        if (isset($irregulars[$lower])) {
            return $irregulars[$lower];
        }

        $rules = [
            '/(quiz)zes$/i' => '$1',
            '/(matr)ices$/i' => '$1ix',
            '/(vert|ind)ices$/i' => '$1ex',
            '/^(ox)en/i' => '$1',
            '/(alias|status)es$/i' => '$1',
            '/(octop|vir)i$/i' => '$1us',
            '/(cris|ax|test)es$/i' => '$1is',
            '/(shoe)s$/i' => '$1',
            '/(o)es$/i' => '$1',
            '/(bus)es$/i' => '$1',
            '/([m|l])ice$/i' => '$1ouse',
            '/(x|ch|ss|sh)es$/i' => '$1',
            '/(m)ovies$/i' => '$1ovie',
            '/(s)eries$/i' => '$1eries',
            '/([^aeiouy]|qu)ies$/i' => '$1y',
            '/([lr])ves$/i' => '$1f',
            '/(tive)s$/i' => '$1',
            '/(hive)s$/i' => '$1',
            '/([^f])ves$/i' => '$1fe',
            '/(^analy)ses$/i' => '$1sis',
            '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i' => '$1$2sis',
            '/([ti])a$/i' => '$1um',
            '/(n)ews$/i' => '$1ews',
            '/s$/i' => '',
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value);
            }
        }

        return $value;
    }
}
