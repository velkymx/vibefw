<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must match a regular expression pattern.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Regex implements Rule
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $message = 'The :field format is invalid.',
    ) {}

    /**
     * Hard ceiling on PCRE backtracking inside a single validate() call.
     * Low enough to bail on catastrophic patterns well under a second,
     * high enough for legitimate validation regexes against normal
     * human-entered strings.
     */
    private const BACKTRACK_LIMIT = 100_000;

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!is_string($value)) {
            return str_replace(':field', $field, $this->message);
        }

        // Constrain PCRE backtracking for this call so a pathological
        // pattern (e.g. `(a+)+$`) can't be weaponised into a ReDoS via
        // crafted input. On error or non-match we fall through to the
        // validation-failure path.
        $originalLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            $matched = @preg_match($this->pattern, $value) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $originalLimit);
        }

        if (!$matched) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'regex:' . $this->pattern;
    }
}
