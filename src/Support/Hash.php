<?php

declare(strict_types=1);

namespace Fw\Support;

final class Hash
{
    /**
     * @param array<string, mixed> $options
     */
    public static function make(string $value, array $options = []): string
    {
        return password_hash($value, PASSWORD_DEFAULT, $options);
    }

    public static function check(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, $options);
    }
}
