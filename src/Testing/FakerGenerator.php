<?php

declare(strict_types=1);

namespace Fw\Testing;

use DateTimeImmutable;
use Fw\Support\Str;

/**
 * Simple fake data generator for testing.
 *
 * Provides common fake data types without external dependencies.
 * For more advanced needs, consider using fakerphp/faker.
 */
final class FakerGenerator
{
    /**
     * Counter for unique values.
     */
    private int $counter = 0;

    /**
     * Used values for unique generation.
     * @var array<string, array<mixed>>
     */
    private array $usedValues = [];

    /**
     * Sample first names.
     * @var array<string>
     */
    private array $firstNames = [
        'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda',
        'William', 'Barbara', 'David', 'Elizabeth', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Daniel', 'Nancy', 'Matthew', 'Lisa',
    ];

    /**
     * Sample last names.
     * @var array<string>
     */
    private array $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
        'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson', 'White',
    ];

    /**
     * Sample words.
     * @var array<string>
     */
    private array $words = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
        'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
        'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud',
    ];

    /**
     * Generate a unique value.
     *
     * @return $this
     */
    public function unique(): self
    {
        $clone = clone $this;
        $clone->counter = ++$this->counter;
        return $clone;
    }

    /**
     * Generate a random first name.
     */
    public function firstName(): string
    {
        return $this->randomElement($this->firstNames);
    }

    /**
     * Generate a random last name.
     */
    public function lastName(): string
    {
        return $this->randomElement($this->lastNames);
    }

    /**
     * Generate a random full name.
     */
    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /**
     * Generate a random email.
     */
    public function email(): string
    {
        $prefix = strtolower($this->firstName()) . '.' . strtolower($this->lastName());
        $prefix = preg_replace('/[^a-z.]/', '', $prefix);

        if ($this->counter > 0) {
            $prefix .= $this->counter;
        }

        $domains = ['example.com', 'test.com', 'fake.org', 'sample.net'];
        return $prefix . '@' . $this->randomElement($domains);
    }

    /**
     * Generate a safe email (always @example.com).
     */
    public function safeEmail(): string
    {
        $prefix = strtolower($this->firstName()) . '.' . strtolower($this->lastName());
        $prefix = preg_replace('/[^a-z.]/', '', $prefix);

        if ($this->counter > 0) {
            $prefix .= $this->counter;
        }

        return $prefix . '@example.com';
    }

    /**
     * Generate a random username.
     */
    public function userName(): string
    {
        $base = strtolower($this->firstName()) . $this->randomNumber(2);

        if ($this->counter > 0) {
            $base .= $this->counter;
        }

        return $base;
    }

    /**
     * Generate a random password.
     */
    public function password(int $minLength = 8, int $maxLength = 16): string
    {
        $length = $this->numberBetween($minLength, $maxLength);
        return Str::random($length);
    }

    /**
     * Generate a UUID.
     */
    public function uuid(): string
    {
        return Str::uuid();
    }

    /**
     * Generate random words.
     */
    public function words(int $count = 3): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->randomElement($this->words);
        }
        return implode(' ', $words);
    }

    /**
     * Generate a sentence.
     */
    public function sentence(int $wordCount = 6): string
    {
        return ucfirst($this->words($wordCount)) . '.';
    }

    /**
     * Generate a paragraph.
     */
    public function paragraph(int $sentenceCount = 3): string
    {
        $sentences = [];
        for ($i = 0; $i < $sentenceCount; $i++) {
            $sentences[] = $this->sentence($this->numberBetween(5, 10));
        }
        return implode(' ', $sentences);
    }

    /**
     * Generate paragraphs of text.
     */
    public function text(int $maxChars = 200): string
    {
        $text = '';
        while (strlen($text) < $maxChars) {
            $text .= $this->paragraph() . "\n\n";
        }
        return trim(substr($text, 0, $maxChars));
    }

    /**
     * Generate a random number.
     */
    public function randomNumber(int $digits = 4): int
    {
        $min = 10 ** ($digits - 1);
        $max = (10 ** $digits) - 1;
        return $this->numberBetween($min, $max);
    }

    /**
     * Generate a number between min and max.
     */
    public function numberBetween(int $min = 0, int $max = 2147483647): int
    {
        return random_int($min, $max);
    }

    /**
     * Generate a random float.
     */
    public function randomFloat(int $decimals = 2, float $min = 0, float $max = 100): float
    {
        $scale = 10 ** $decimals;
        return random_int((int)($min * $scale), (int)($max * $scale)) / $scale;
    }

    /**
     * Generate a boolean.
     */
    public function boolean(int $chanceOfTrue = 50): bool
    {
        return random_int(1, 100) <= $chanceOfTrue;
    }

    /**
     * Generate a date.
     */
    public function date(string $format = 'Y-m-d', string $max = 'now'): string
    {
        $timestamp = strtotime($max) - random_int(0, 365 * 24 * 60 * 60);
        return date($format, $timestamp);
    }

    /**
     * Generate a datetime.
     */
    public function dateTime(string $max = 'now'): DateTimeImmutable
    {
        $timestamp = strtotime($max) - random_int(0, 365 * 24 * 60 * 60);
        return new DateTimeImmutable('@' . $timestamp);
    }

    /**
     * Generate a date between two dates.
     */
    public function dateTimeBetween(string $start = '-30 years', string $end = 'now'): DateTimeImmutable
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        $timestamp = random_int($startTs, $endTs);
        return new DateTimeImmutable('@' . $timestamp);
    }

    /**
     * Generate a URL.
     */
    public function url(): string
    {
        $domains = ['example.com', 'test.org', 'sample.net'];
        return 'https://' . $this->randomElement($domains) . '/' . $this->slug();
    }

    /**
     * Generate a slug.
     */
    public function slug(int $words = 3): string
    {
        return Str::slug($this->words($words));
    }

    /**
     * Generate a phone number.
     */
    public function phoneNumber(): string
    {
        return sprintf(
            '(%03d) %03d-%04d',
            $this->numberBetween(200, 999),
            $this->numberBetween(200, 999),
            $this->numberBetween(1000, 9999)
        );
    }

    /**
     * Generate a company name.
     */
    public function company(): string
    {
        $suffixes = ['Inc', 'LLC', 'Corp', 'Ltd', 'Group', 'Solutions'];
        return $this->lastName() . ' ' . $this->randomElement($suffixes);
    }

    /**
     * Generate a street address.
     */
    public function streetAddress(): string
    {
        $streets = ['Main St', 'Oak Ave', 'Park Blvd', 'First St', 'Market St'];
        return $this->numberBetween(100, 9999) . ' ' . $this->randomElement($streets);
    }

    /**
     * Generate a city.
     */
    public function city(): string
    {
        $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia'];
        return $this->randomElement($cities);
    }

    /**
     * Generate a state abbreviation.
     */
    public function stateAbbr(): string
    {
        $states = ['NY', 'CA', 'TX', 'FL', 'IL', 'PA', 'OH', 'GA', 'NC', 'MI'];
        return $this->randomElement($states);
    }

    /**
     * Generate a zip code.
     */
    public function postcode(): string
    {
        return (string) $this->numberBetween(10000, 99999);
    }

    /**
     * Generate a country.
     */
    public function country(): string
    {
        $countries = ['United States', 'Canada', 'United Kingdom', 'Australia', 'Germany'];
        return $this->randomElement($countries);
    }

    /**
     * Pick a random element from an array.
     *
     * @template T
     * @param array<T> $array
     * @return T
     */
    public function randomElement(array $array): mixed
    {
        return $array[array_rand($array)];
    }

    /**
     * Pick multiple random elements from an array.
     *
     * @template T
     * @param array<T> $array
     * @return array<T>
     */
    public function randomElements(array $array, int $count = 2): array
    {
        $keys = array_rand($array, min($count, count($array)));
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_map(fn ($k) => $array[$k], $keys);
    }
}
