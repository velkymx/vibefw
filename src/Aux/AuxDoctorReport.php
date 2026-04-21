<?php

declare(strict_types=1);

namespace Fw\Aux;

final class AuxDoctorReport
{
    private const array BUILTIN_LEVELS = [1, 2, 3];

    public int $level {
        get {
            foreach (self::BUILTIN_LEVELS as $lvl) {
                if (!$this->allPassedAt($lvl)) {
                    return $lvl - 1;
                }
            }

            return 3;
        }
    }

    public int $score {
        get => count(array_filter($this->checks, static fn (array $c): bool => $c['passed']));
    }

    public int $total {
        get => count($this->checks);
    }

    /**
     * @param list<array{level: int, name: string, passed: bool, hint: string}> $checks
     */
    public function __construct(public readonly array $checks) {}

    private function allPassedAt(int $level): bool
    {
        foreach ($this->checks as $check) {
            if ($check['level'] === $level && !$check['passed']) {
                return false;
            }
        }

        return true;
    }
}
