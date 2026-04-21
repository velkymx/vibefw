<?php

declare(strict_types=1);

namespace Fw\Aux;

final readonly class FeatureIndex
{
    /**
     * @param list<Feature> $features
     */
    public function __construct(public array $features = []) {}

    public function with(Feature $feature): self
    {
        return clone($this, ['features' => [...$this->features, $feature]]);
    }

    /**
     * @param list<string> $callerAbilities
     */
    public function forAbilities(array $callerAbilities): self
    {
        $visible = array_values(array_filter(
            $this->features,
            fn (Feature $f): bool => $f->isAccessibleWith($callerAbilities),
        ));

        return clone($this, ['features' => $visible]);
    }

    /**
     * @return list<array{name: string, description: string, url: string, abilities: list<string>}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (Feature $f): array => $f->toArray(),
            $this->features,
        );
    }
}
