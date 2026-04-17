<?php

declare(strict_types=1);

namespace Fw\Aux;

final readonly class Feature
{
    /**
     * @param list<string> $abilities Token abilities required (any one suffices)
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $url,
        public array $abilities = [],
    ) {}

    /**
     * @param list<string> $callerAbilities
     */
    public function isAccessibleWith(array $callerAbilities): bool
    {
        if ($this->abilities === []) {
            return true;
        }

        foreach ($this->abilities as $required) {
            if (in_array($required, $callerAbilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, description: string, url: string, abilities: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'abilities' => $this->abilities,
        ];
    }
}
