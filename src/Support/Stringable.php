<?php

declare(strict_types=1);

namespace Fw\Support;

use ArrayAccess;
use Closure;
use Countable;
use JsonSerializable;
use Stringable as BaseStringable;

/**
 * Fluent string manipulation class.
 *
 * Provides chainable methods for string operations,
 * allowing expressive string processing pipelines.
 *
 * @example
 * Str::of('hello world')
 *     ->title()
 *     ->replace(' ', '-')
 *     ->append('!')
 *     ->value(); // "Hello-World!"
 */
final class Stringable implements BaseStringable, JsonSerializable, ArrayAccess, Countable
{
    /**
     * The underlying string value.
     */
    private string $value;

    /**
     * Create a new Stringable instance.
     */
    public function __construct(string $value = '')
    {
        $this->value = $value;
    }

    /**
     * Get the raw string value.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Get the underlying string value.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get the underlying string value.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Get the underlying string value as an integer.
     */
    public function toInteger(): int
    {
        return (int) $this->value;
    }

    /**
     * Get the underlying string value as a float.
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /**
     * Get the underlying string value as a boolean.
     */
    public function toBoolean(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     */
    public function after(string $search): static
    {
        return new self(Str::after($this->value, $search));
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     */
    public function afterLast(string $search): static
    {
        return new static(Str::afterLast($this->value, $search));
    }

    /**
     * Append the given values to the string.
     */
    public function append(string ...$values): static
    {
        return new static($this->value . implode('', $values));
    }

    /**
     * Transliterate a UTF-8 value to ASCII.
     */
    public function ascii(string $language = 'en'): static
    {
        return new static(Str::ascii($this->value, $language));
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     */
    public function before(string $search): static
    {
        return new static(Str::before($this->value, $search));
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     */
    public function beforeLast(string $search): static
    {
        return new static(Str::beforeLast($this->value, $search));
    }

    /**
     * Get the portion of a string between two given values.
     */
    public function between(string $from, string $to): static
    {
        return new static(Str::between($this->value, $from, $to));
    }

    /**
     * Get the smallest possible portion of a string between two given values.
     */
    public function betweenFirst(string $from, string $to): static
    {
        return new static(Str::betweenFirst($this->value, $from, $to));
    }

    /**
     * Convert a value to camelCase.
     */
    public function camel(): static
    {
        return new static(Str::camel($this->value));
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public function contains(string|iterable $needles, bool $ignoreCase = false): bool
    {
        return Str::contains($this->value, $needles, $ignoreCase);
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param iterable<string> $needles
     */
    public function containsAll(iterable $needles, bool $ignoreCase = false): bool
    {
        return Str::containsAll($this->value, $needles, $ignoreCase);
    }

    /**
     * Determine if a given string ends with a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public function endsWith(string|iterable $needles): bool
    {
        return Str::endsWith($this->value, $needles);
    }

    /**
     * Determine if the string is an exact match with the given value.
     */
    public function exactly(string $value): bool
    {
        return $this->value === $value;
    }

    /**
     * Extracts an excerpt from text that matches the first instance of a phrase.
     */
    public function excerpt(string $phrase = '', array $options = []): ?static
    {
        $result = Str::excerpt($this->value, $phrase, $options);

        return $result !== null ? new static($result) : null;
    }

    /**
     * Explode the string into an array.
     *
     * @return array<int, string>
     */
    public function explode(string $delimiter, int $limit = PHP_INT_MAX): array
    {
        return explode($delimiter, $this->value, $limit);
    }

    /**
     * Cap a string with a single instance of a given value.
     */
    public function finish(string $cap): static
    {
        return new static(Str::finish($this->value, $cap));
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param string|iterable<string> $pattern
     */
    public function is(string|iterable $pattern): bool
    {
        return Str::is($pattern, $this->value);
    }

    /**
     * Determine if the string is 7-bit ASCII.
     */
    public function isAscii(): bool
    {
        return Str::isAscii($this->value);
    }

    /**
     * Determine if the string is empty.
     */
    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * Determine if the string is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Determine if the string is valid JSON.
     */
    public function isJson(): bool
    {
        return Str::isJson($this->value);
    }

    /**
     * Determine if the string is a valid UUID.
     */
    public function isUuid(): bool
    {
        return Str::isUuid($this->value);
    }

    /**
     * Determine if the string is a valid ULID.
     */
    public function isUlid(): bool
    {
        return Str::isUlid($this->value);
    }

    /**
     * Convert a string to kebab-case.
     */
    public function kebab(): static
    {
        return new static(Str::kebab($this->value));
    }

    /**
     * Make a string's first character lowercase.
     */
    public function lcfirst(): static
    {
        return new static(Str::lcfirst($this->value));
    }

    /**
     * Return the length of the given string.
     */
    public function length(?string $encoding = null): int
    {
        return Str::length($this->value, $encoding);
    }

    /**
     * Limit the number of characters in a string.
     */
    public function limit(int $limit = 100, string $end = '...'): static
    {
        return new static(Str::limit($this->value, $limit, $end));
    }

    /**
     * Convert the given string to lower-case.
     */
    public function lower(): static
    {
        return new static(Str::lower($this->value));
    }

    /**
     * Convert GitHub-flavored Markdown into HTML.
     */
    public function markdown(array $options = []): static
    {
        // Basic markdown conversion - could be expanded with a proper library
        $html = $this->value;

        // Headers
        $html = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Links
        $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);

        // Paragraphs
        $html = '<p>' . preg_replace('/\n\n+/', '</p><p>', $html) . '</p>';

        return new static($html);
    }

    /**
     * Masks a portion of a string with a repeated character.
     */
    public function mask(string $character, int $index, ?int $length = null, string $encoding = 'UTF-8'): static
    {
        return new static(Str::mask($this->value, $character, $index, $length, $encoding));
    }

    /**
     * Get the string matching the given pattern.
     */
    public function match(string $pattern): static
    {
        preg_match($pattern, $this->value, $matches);

        return new static($matches[1] ?? $matches[0] ?? '');
    }

    /**
     * Get the strings matching the given pattern.
     *
     * @return array<int, string>
     */
    public function matchAll(string $pattern): array
    {
        preg_match_all($pattern, $this->value, $matches);

        return $matches[1] ?? $matches[0] ?? [];
    }

    /**
     * Determine if the string matches the given pattern.
     */
    public function test(string $pattern): bool
    {
        return (bool) preg_match($pattern, $this->value);
    }

    /**
     * Pad both sides of the string with another.
     */
    public function padBoth(int $length, string $pad = ' '): static
    {
        return new static(Str::padBoth($this->value, $length, $pad));
    }

    /**
     * Pad the left side of the string with another.
     */
    public function padLeft(int $length, string $pad = ' '): static
    {
        return new static(Str::padLeft($this->value, $length, $pad));
    }

    /**
     * Pad the right side of the string with another.
     */
    public function padRight(int $length, string $pad = ' '): static
    {
        return new static(Str::padRight($this->value, $length, $pad));
    }

    /**
     * Parse a Class[@]method style callback into class and method.
     *
     * @return array{0: string, 1: string|null}
     */
    public function parseCallback(?string $default = null): array
    {
        return Str::parseCallback($this->value, $default);
    }

    /**
     * Call the given callback and return a new string.
     */
    public function pipe(callable $callback): static
    {
        return new static($callback($this));
    }

    /**
     * Get the plural form of an English word.
     */
    public function plural(int|array|Countable $count = 2): static
    {
        return new static(Str::plural($this->value, $count));
    }

    /**
     * Prepend the given values to the string.
     */
    public function prepend(string ...$values): static
    {
        return new static(implode('', $values) . $this->value);
    }

    /**
     * Remove any occurrence of the given string in the subject.
     *
     * @param string|iterable<string> $search
     */
    public function remove(string|iterable $search, bool $caseSensitive = true): static
    {
        return new static(Str::replace($search, '', $this->value, $caseSensitive));
    }

    /**
     * Repeat the string.
     */
    public function repeat(int $times): static
    {
        return new static(Str::repeat($this->value, $times));
    }

    /**
     * Replace the given value in the given string.
     *
     * @param string|iterable<string> $search
     * @param string|iterable<string> $replace
     */
    public function replace(string|iterable $search, string|iterable $replace, bool $caseSensitive = true): static
    {
        return new static(Str::replace($search, $replace, $this->value, $caseSensitive));
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param array<int, string> $replace
     */
    public function replaceArray(string $search, array $replace): static
    {
        return new static(Str::replaceArray($search, $replace, $this->value));
    }

    /**
     * Replace the first occurrence of a given value in the string.
     */
    public function replaceFirst(string $search, string $replace): static
    {
        return new static(Str::replaceFirst($search, $replace, $this->value));
    }

    /**
     * Replace the last occurrence of a given value in the string.
     */
    public function replaceLast(string $search, string $replace): static
    {
        return new static(Str::replaceLast($search, $replace, $this->value));
    }

    /**
     * Replace the patterns matching the given regular expression.
     *
     * @param string|array<string> $pattern
     * @param string|callable $replace
     */
    public function replaceMatches(string|array $pattern, string|callable $replace, int $limit = -1): static
    {
        if (is_callable($replace)) {
            return new static(preg_replace_callback($pattern, $replace, $this->value, $limit));
        }

        return new static(preg_replace($pattern, $replace, $this->value, $limit));
    }

    /**
     * Reverse the string.
     */
    public function reverse(): static
    {
        return new static(Str::reverse($this->value));
    }

    /**
     * Begin a string with a single instance of a given value.
     */
    public function start(string $prefix): static
    {
        return new static(Str::start($this->value, $prefix));
    }

    /**
     * Convert the given string to upper-case.
     */
    public function upper(): static
    {
        return new static(Str::upper($this->value));
    }

    /**
     * Convert the given string to title case.
     */
    public function title(): static
    {
        return new static(Str::title($this->value));
    }

    /**
     * Convert the given string to title case for each word.
     */
    public function headline(): static
    {
        return new static(Str::headline($this->value));
    }

    /**
     * Get the singular form of an English word.
     */
    public function singular(): static
    {
        return new static(Str::singular($this->value));
    }

    /**
     * Generate a URL-friendly "slug" from a given string.
     */
    public function slug(string $separator = '-', ?string $language = 'en', array $dictionary = ['@' => 'at']): static
    {
        return new static(Str::slug($this->value, $separator, $language, $dictionary));
    }

    /**
     * Convert a string to snake_case.
     */
    public function snake(string $delimiter = '_'): static
    {
        return new static(Str::snake($this->value, $delimiter));
    }

    /**
     * Remove all whitespace from both ends of a string.
     */
    public function squish(): static
    {
        return new static(Str::squish($this->value));
    }

    /**
     * Determine if a given string starts with a given substring.
     *
     * @param string|iterable<string> $needles
     */
    public function startsWith(string|iterable $needles): bool
    {
        return Str::startsWith($this->value, $needles);
    }

    /**
     * Convert a value to StudlyCase.
     */
    public function studly(): static
    {
        return new static(Str::studly($this->value));
    }

    /**
     * Returns the portion of the string specified by the start and length parameters.
     */
    public function substr(int $start, ?int $length = null, string $encoding = 'UTF-8'): static
    {
        return new static(Str::substr($this->value, $start, $length, $encoding));
    }

    /**
     * Returns the number of substring occurrences.
     */
    public function substrCount(string $needle, int $offset = 0, ?int $length = null): int
    {
        return Str::substrCount($this->value, $needle, $offset, $length);
    }

    /**
     * Replace text within a portion of a string.
     */
    public function substrReplace(string $replace, int $offset = 0, ?int $length = null): static
    {
        return new static(Str::substrReplace($this->value, $replace, $offset, $length));
    }

    /**
     * Swap multiple keywords in a string with other keywords.
     *
     * @param array<string, string> $map
     */
    public function swap(array $map): static
    {
        return new static(Str::swap($map, $this->value));
    }

    /**
     * Take the first or last {$limit} characters.
     */
    public function take(int $limit): static
    {
        return new static(Str::take($this->value, $limit));
    }

    /**
     * Call the given Closure with this instance then return the instance.
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Trim the string of the given characters.
     */
    public function trim(?string $characters = null): static
    {
        return new static(Str::trim($this->value, $characters));
    }

    /**
     * Left trim the string of the given characters.
     */
    public function ltrim(?string $characters = null): static
    {
        return new static(Str::ltrim($this->value, $characters));
    }

    /**
     * Right trim the string of the given characters.
     */
    public function rtrim(?string $characters = null): static
    {
        return new static(Str::rtrim($this->value, $characters));
    }

    /**
     * Make a string's first character uppercase.
     */
    public function ucfirst(): static
    {
        return new static(Str::ucfirst($this->value));
    }

    /**
     * Split a string by uppercase characters.
     *
     * @return array<int, string>
     */
    public function ucsplit(): array
    {
        return Str::ucsplit($this->value);
    }

    /**
     * Execute the given callback if the string is empty.
     */
    public function whenEmpty(callable $callback, ?callable $default = null): static
    {
        if ($this->isEmpty()) {
            return $callback($this) ?? $this;
        }

        if ($default !== null) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Execute the given callback if the string is not empty.
     */
    public function whenNotEmpty(callable $callback, ?callable $default = null): static
    {
        if ($this->isNotEmpty()) {
            return $callback($this) ?? $this;
        }

        if ($default !== null) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Execute the given callback if the given "value" is truthy.
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if ($value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Execute the given callback if the given "value" is falsy.
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): static
    {
        $value = $value instanceof Closure ? $value($this) : $value;

        if (!$value) {
            return $callback($this, $value) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * Limit the number of words in a string.
     */
    public function words(int $words = 100, string $end = '...'): static
    {
        return new static(Str::words($this->value, $words, $end));
    }

    /**
     * Get the number of words a string contains.
     */
    public function wordCount(?string $characters = null): int
    {
        return Str::wordCount($this->value, $characters);
    }

    /**
     * Wrap a string to a given number of characters.
     */
    public function wordWrap(int $characters = 75, string $break = "\n", bool $cutLongWords = false): static
    {
        return new static(Str::wordWrap($this->value, $characters, $break, $cutLongWords));
    }

    /**
     * Wrap the string with the given strings.
     */
    public function wrap(string $before, ?string $after = null): static
    {
        return new static(Str::wrap($this->value, $before, $after));
    }

    /**
     * Dump the string.
     */
    public function dump(): static
    {
        var_dump($this->value);

        return $this;
    }

    /**
     * Dump the string and die.
     */
    public function dd(): never
    {
        $this->dump();

        exit(1);
    }

    /**
     * Convert the object to a string when JSON encoded.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->value[$offset]);
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): string
    {
        return $this->value[$offset];
    }

    /**
     * Set the value at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->value[$offset] = $value;
    }

    /**
     * Unset the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->value[$offset]);
    }

    /**
     * Get the number of characters in the string.
     */
    public function count(): int
    {
        return $this->length();
    }
}
