<?php

declare(strict_types=1);

namespace Fw\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable DateTime helper with fluent API.
 *
 * Wraps DateTimeImmutable with convenient factory methods,
 * manipulation methods, and comparison helpers.
 *
 * @example
 *     // Factory methods
 *     $now = DateTime::now();
 *     $today = DateTime::today();
 *     $date = DateTime::parse('2024-01-15');
 *     $date = DateTime::create(2024, 1, 15, 10, 30);
 *
 *     // Manipulation (returns new instance)
 *     $tomorrow = $now->addDays(1);
 *     $lastMonth = $now->subMonths(1);
 *     $startOfWeek = $now->startOfWeek();
 *
 *     // Comparisons
 *     $now->isBefore($tomorrow);  // true
 *     $now->isAfter($yesterday);  // true
 *     $now->isBetween($start, $end);
 *
 *     // Formatting
 *     $now->format('Y-m-d');
 *     $now->toIso8601();
 *     $now->diffForHumans();  // "2 hours ago"
 */
final readonly class DateTime implements Stringable, JsonSerializable
{
    public const string FORMAT_DATE = 'Y-m-d';
    public const string FORMAT_TIME = 'H:i:s';
    public const string FORMAT_DATETIME = 'Y-m-d H:i:s';
    public const string FORMAT_ISO8601 = 'c';
    public const string FORMAT_RFC2822 = 'r';
    public const string FORMAT_ATOM = DateTimeInterface::ATOM;

    private function __construct(
        public DateTimeImmutable $value
    ) {}

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    // ========================================
    // FACTORY METHODS
    // ========================================

    /**
     * Create from DateTimeImmutable.
     */
    public static function from(DateTimeImmutable $datetime): self
    {
        return new self($datetime);
    }

    /**
     * Parse a datetime string.
     *
     * @throws InvalidArgumentException If parsing fails
     */
    public static function parse(string $datetime, ?DateTimeZone $timezone = null): self
    {
        try {
            return new self(new DateTimeImmutable($datetime, $timezone));
        } catch (Exception $e) {
            throw new InvalidArgumentException("Cannot parse datetime: {$datetime}", 0, $e);
        }
    }

    /**
     * Create from Unix timestamp.
     */
    public static function fromTimestamp(int $timestamp, ?DateTimeZone $timezone = null): self
    {
        $datetime = (new DateTimeImmutable('@' . $timestamp));

        if ($timezone !== null) {
            $datetime = $datetime->setTimezone($timezone);
        }

        return new self($datetime);
    }

    /**
     * Create from date/time components.
     */
    public static function create(
        int $year,
        int $month = 1,
        int $day = 1,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        ?DateTimeZone $timezone = null
    ): self {
        $datetime = new DateTimeImmutable('now', $timezone)
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second, 0);

        return new self($datetime);
    }

    /**
     * Create for current moment.
     */
    public static function now(?DateTimeZone $timezone = null): self
    {
        return new self(new DateTimeImmutable('now', $timezone));
    }

    /**
     * Create for today at midnight.
     */
    public static function today(?DateTimeZone $timezone = null): self
    {
        return new self(new DateTimeImmutable('today', $timezone));
    }

    /**
     * Create for yesterday at midnight.
     */
    public static function yesterday(?DateTimeZone $timezone = null): self
    {
        return new self(new DateTimeImmutable('yesterday', $timezone));
    }

    /**
     * Create for tomorrow at midnight.
     */
    public static function tomorrow(?DateTimeZone $timezone = null): self
    {
        return new self(new DateTimeImmutable('tomorrow', $timezone));
    }

    /**
     * Wrap a value - returns as-is if already DateTime, otherwise parses.
     * Used by Model auto-casting.
     */
    public static function wrap(string|DateTimeInterface|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof DateTimeImmutable) {
            return new self($value);
        }

        if ($value instanceof DateTimeInterface) {
            return new self(DateTimeImmutable::createFromInterface($value));
        }

        return self::parse($value);
    }

    // ========================================
    // COMPONENT ACCESSORS
    // ========================================

    public function year(): int
    {
        return (int) $this->value->format('Y');
    }

    public function month(): int
    {
        return (int) $this->value->format('n');
    }

    public function day(): int
    {
        return (int) $this->value->format('j');
    }

    public function hour(): int
    {
        return (int) $this->value->format('G');
    }

    public function minute(): int
    {
        return (int) $this->value->format('i');
    }

    public function second(): int
    {
        return (int) $this->value->format('s');
    }

    public function dayOfWeek(): int
    {
        return (int) $this->value->format('w'); // 0 (Sunday) to 6 (Saturday)
    }

    public function dayOfYear(): int
    {
        return (int) $this->value->format('z') + 1; // 1-366
    }

    public function weekOfYear(): int
    {
        return (int) $this->value->format('W');
    }

    public function daysInMonth(): int
    {
        return (int) $this->value->format('t');
    }

    public function timestamp(): int
    {
        return $this->value->getTimestamp();
    }

    public function timezone(): DateTimeZone
    {
        return $this->value->getTimezone();
    }

    // ========================================
    // DAY CHECKS
    // ========================================

    public function isWeekday(): bool
    {
        return $this->dayOfWeek() >= 1 && $this->dayOfWeek() <= 5;
    }

    public function isWeekend(): bool
    {
        return $this->dayOfWeek() === 0 || $this->dayOfWeek() === 6;
    }

    public function isMonday(): bool
    {
        return $this->dayOfWeek() === 1;
    }

    public function isTuesday(): bool
    {
        return $this->dayOfWeek() === 2;
    }

    public function isWednesday(): bool
    {
        return $this->dayOfWeek() === 3;
    }

    public function isThursday(): bool
    {
        return $this->dayOfWeek() === 4;
    }

    public function isFriday(): bool
    {
        return $this->dayOfWeek() === 5;
    }

    public function isSaturday(): bool
    {
        return $this->dayOfWeek() === 6;
    }

    public function isSunday(): bool
    {
        return $this->dayOfWeek() === 0;
    }

    public function isToday(): bool
    {
        return $this->format(self::FORMAT_DATE) === self::today($this->timezone())->format(self::FORMAT_DATE);
    }

    public function isYesterday(): bool
    {
        return $this->format(self::FORMAT_DATE) === self::yesterday($this->timezone())->format(self::FORMAT_DATE);
    }

    public function isTomorrow(): bool
    {
        return $this->format(self::FORMAT_DATE) === self::tomorrow($this->timezone())->format(self::FORMAT_DATE);
    }

    public function isPast(): bool
    {
        return $this->value < new DateTimeImmutable('now', $this->timezone());
    }

    public function isFuture(): bool
    {
        return $this->value > new DateTimeImmutable('now', $this->timezone());
    }

    public function isLeapYear(): bool
    {
        return (bool) $this->value->format('L');
    }

    // ========================================
    // MANIPULATION - ADD
    // ========================================

    public function addYears(int $years): self
    {
        return $this->modify("{$years} years");
    }

    public function addMonths(int $months): self
    {
        return $this->modify("{$months} months");
    }

    public function addWeeks(int $weeks): self
    {
        return $this->modify("{$weeks} weeks");
    }

    public function addDays(int $days): self
    {
        return $this->modify("{$days} days");
    }

    public function addHours(int $hours): self
    {
        return $this->modify("{$hours} hours");
    }

    public function addMinutes(int $minutes): self
    {
        return $this->modify("{$minutes} minutes");
    }

    public function addSeconds(int $seconds): self
    {
        return $this->modify("{$seconds} seconds");
    }

    public function add(DateInterval $interval): self
    {
        return new self($this->value->add($interval));
    }

    // ========================================
    // MANIPULATION - SUBTRACT
    // ========================================

    public function subYears(int $years): self
    {
        return $this->modify("-{$years} years");
    }

    public function subMonths(int $months): self
    {
        return $this->modify("-{$months} months");
    }

    public function subWeeks(int $weeks): self
    {
        return $this->modify("-{$weeks} weeks");
    }

    public function subDays(int $days): self
    {
        return $this->modify("-{$days} days");
    }

    public function subHours(int $hours): self
    {
        return $this->modify("-{$hours} hours");
    }

    public function subMinutes(int $minutes): self
    {
        return $this->modify("-{$minutes} minutes");
    }

    public function subSeconds(int $seconds): self
    {
        return $this->modify("-{$seconds} seconds");
    }

    public function sub(DateInterval $interval): self
    {
        return new self($this->value->sub($interval));
    }

    // ========================================
    // MANIPULATION - BOUNDARIES
    // ========================================

    public function startOfDay(): self
    {
        return new self($this->value->setTime(0, 0, 0));
    }

    public function endOfDay(): self
    {
        return new self($this->value->setTime(23, 59, 59));
    }

    public function startOfWeek(): self
    {
        $dayOfWeek = $this->dayOfWeek();
        // Assuming week starts on Monday (1)
        $diff = $dayOfWeek === 0 ? 6 : $dayOfWeek - 1;

        return $this->subDays($diff)->startOfDay();
    }

    public function endOfWeek(): self
    {
        $dayOfWeek = $this->dayOfWeek();
        // Assuming week ends on Sunday (0)
        $diff = $dayOfWeek === 0 ? 0 : 7 - $dayOfWeek;

        return $this->addDays($diff)->endOfDay();
    }

    public function startOfMonth(): self
    {
        return new self($this->value->setDate($this->year(), $this->month(), 1)->setTime(0, 0, 0));
    }

    public function endOfMonth(): self
    {
        return new self($this->value->setDate($this->year(), $this->month(), $this->daysInMonth())->setTime(23, 59, 59));
    }

    public function startOfYear(): self
    {
        return new self($this->value->setDate($this->year(), 1, 1)->setTime(0, 0, 0));
    }

    public function endOfYear(): self
    {
        return new self($this->value->setDate($this->year(), 12, 31)->setTime(23, 59, 59));
    }

    // ========================================
    // MANIPULATION - SET
    // ========================================

    public function setYear(int $year): self
    {
        return new self($this->value->setDate($year, $this->month(), $this->day()));
    }

    public function setMonth(int $month): self
    {
        return new self($this->value->setDate($this->year(), $month, $this->day()));
    }

    public function setDay(int $day): self
    {
        return new self($this->value->setDate($this->year(), $this->month(), $day));
    }

    public function setHour(int $hour): self
    {
        return new self($this->value->setTime($hour, $this->minute(), $this->second()));
    }

    public function setMinute(int $minute): self
    {
        return new self($this->value->setTime($this->hour(), $minute, $this->second()));
    }

    public function setSecond(int $second): self
    {
        return new self($this->value->setTime($this->hour(), $this->minute(), $second));
    }

    public function setDate(int $year, int $month, int $day): self
    {
        return new self($this->value->setDate($year, $month, $day));
    }

    public function setTime(int $hour, int $minute, int $second = 0): self
    {
        return new self($this->value->setTime($hour, $minute, $second));
    }

    public function setTimezone(DateTimeZone|string $timezone): self
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        return new self($this->value->setTimezone($timezone));
    }

    // ========================================
    // COMPARISONS
    // ========================================

    public function equals(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value == $otherValue;
    }

    public function isBefore(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value < $otherValue;
    }

    public function isAfter(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value > $otherValue;
    }

    public function isBeforeOrEqual(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value <= $otherValue;
    }

    public function isAfterOrEqual(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value >= $otherValue;
    }

    public function isBetween(
        self|DateTimeInterface $start,
        self|DateTimeInterface $end,
        bool $inclusive = true
    ): bool {
        if ($inclusive) {
            return $this->isAfterOrEqual($start) && $this->isBeforeOrEqual($end);
        }

        return $this->isAfter($start) && $this->isBefore($end);
    }

    public function isSameDay(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->format(self::FORMAT_DATE) === $otherValue->format(self::FORMAT_DATE);
    }

    public function isSameMonth(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->format('Y-m') === $otherValue->format('Y-m');
    }

    public function isSameYear(self|DateTimeInterface $other): bool
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->format('Y') === $otherValue->format('Y');
    }

    public function min(self|DateTimeInterface $other): self
    {
        return $this->isBefore($other) ? $this : self::wrap($other);
    }

    public function max(self|DateTimeInterface $other): self
    {
        return $this->isAfter($other) ? $this : self::wrap($other);
    }

    // ========================================
    // DIFFERENCE
    // ========================================

    public function diff(self|DateTimeInterface $other, bool $absolute = false): DateInterval
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $this->value->diff($otherValue, $absolute);
    }

    public function diffInYears(self|DateTimeInterface $other): int
    {
        return (int) $this->diff($other)->format('%r%y');
    }

    public function diffInMonths(self|DateTimeInterface $other): int
    {
        $diff = $this->diff($other);
        $months = $diff->y * 12 + $diff->m;

        return $diff->invert ? -$months : $months;
    }

    public function diffInWeeks(self|DateTimeInterface $other): int
    {
        return (int) floor($this->diffInDays($other) / 7);
    }

    public function diffInDays(self|DateTimeInterface $other): int
    {
        return (int) $this->diff($other)->format('%r%a');
    }

    public function diffInHours(self|DateTimeInterface $other): int
    {
        $diff = $this->diff($other);
        $hours = $diff->days * 24 + $diff->h;

        return $diff->invert ? -$hours : $hours;
    }

    public function diffInMinutes(self|DateTimeInterface $other): int
    {
        $diff = $this->diff($other);
        $minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

        return $diff->invert ? -$minutes : $minutes;
    }

    public function diffInSeconds(self|DateTimeInterface $other): int
    {
        $otherValue = $other instanceof self ? $other->value : $other;

        return $otherValue->getTimestamp() - $this->value->getTimestamp();
    }

    /**
     * Get human-readable difference from now or given time.
     *
     * @example
     *     $date->diffForHumans();          // "2 hours ago"
     *     $date->diffForHumans($other);    // "3 days before"
     *     $date->diffForHumans(parts: 2);  // "2 hours, 30 minutes ago"
     */
    public function diffForHumans(
        self|DateTimeInterface|null $other = null,
        int $parts = 1,
        bool $short = false
    ): string {
        $comparingToNow = $other === null;
        $other ??= self::now($this->timezone());
        $diff = $this->diff($other);
        $isFuture = $diff->invert === 1;

        $units = [
            'y' => $short ? 'y' : ['year', 'years'],
            'm' => $short ? 'mo' : ['month', 'months'],
            'd' => $short ? 'd' : ['day', 'days'],
            'h' => $short ? 'h' : ['hour', 'hours'],
            'i' => $short ? 'm' : ['minute', 'minutes'],
            's' => $short ? 's' : ['second', 'seconds'],
        ];

        $segments = [];

        foreach ($units as $key => $label) {
            $value = match ($key) {
                'y' => $diff->y,
                'm' => $diff->m,
                'd' => $diff->d,
                'h' => $diff->h,
                'i' => $diff->i,
                's' => $diff->s,
            };

            if ($value > 0 && count($segments) < $parts) {
                if ($short) {
                    $segments[] = "{$value}{$label}";
                } else {
                    $segments[] = "{$value} " . ($value === 1 ? $label[0] : $label[1]);
                }
            }
        }

        if (empty($segments)) {
            return $short ? 'now' : 'just now';
        }

        $humanDiff = implode($short ? ' ' : ', ', $segments);

        if ($comparingToNow) {
            $suffix = $isFuture ? 'from now' : 'ago';
        } else {
            $suffix = $isFuture ? 'after' : 'before';
        }

        return $short ? $humanDiff : "{$humanDiff} {$suffix}";
    }

    /**
     * Get age in years from this date to now.
     */
    public function age(): int
    {
        return abs($this->diffInYears(self::now($this->timezone())));
    }

    // ========================================
    // FORMATTING
    // ========================================

    public function format(string $format): string
    {
        return $this->value->format($format);
    }

    public function toDateString(): string
    {
        return $this->format(self::FORMAT_DATE);
    }

    public function toTimeString(): string
    {
        return $this->format(self::FORMAT_TIME);
    }

    public function toDateTimeString(): string
    {
        return $this->format(self::FORMAT_DATETIME);
    }

    public function toIso8601(): string
    {
        return $this->format(self::FORMAT_ISO8601);
    }

    public function toRfc2822(): string
    {
        return $this->format(self::FORMAT_RFC2822);
    }

    public function toAtom(): string
    {
        return $this->format(self::FORMAT_ATOM);
    }

    // ========================================
    // CONVERSION
    // ========================================

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->value;
    }

    public function toDateTime(): \DateTime
    {
        return \DateTime::createFromImmutable($this->value);
    }

    // ========================================
    // SERIALIZATION
    // ========================================

    public function jsonSerialize(): string
    {
        return $this->toIso8601();
    }

    // ========================================
    // INTERNAL
    // ========================================

    private function modify(string $modifier): self
    {
        $modified = $this->value->modify($modifier);

        if ($modified === false) {
            throw new InvalidArgumentException("Invalid modifier: {$modifier}");
        }

        return new self($modified);
    }
}
