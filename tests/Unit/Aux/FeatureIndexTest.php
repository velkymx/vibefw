<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Feature;
use Fw\Aux\FeatureIndex;
use PHPUnit\Framework\TestCase;

final class FeatureIndexTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $index = new FeatureIndex();

        $this->assertSame([], $index->features);
        $this->assertSame([], $index->toArray());
    }

    public function testWithReturnsNewIndexWithAppendedFeature(): void
    {
        $a = new Feature('dashboard', 'Main dashboard', '/dashboard');
        $b = new Feature('settings', 'User settings', '/settings');

        $index = (new FeatureIndex())->with($a)->with($b);

        $this->assertSame([$a, $b], $index->features);
    }

    public function testWithIsImmutable(): void
    {
        $original = new FeatureIndex();
        $added = $original->with(new Feature('x', 'x', '/x'));

        $this->assertSame([], $original->features);
        $this->assertCount(1, $added->features);
    }

    public function testForAbilitiesFiltersByAccess(): void
    {
        $public = new Feature('home', 'Homepage', '/', []);
        $admin = new Feature('admin', 'Admin panel', '/admin', ['admin']);
        $support = new Feature('tickets', 'Tickets', '/tickets', ['support']);

        $index = (new FeatureIndex())->with($public)->with($admin)->with($support);

        $forSupport = $index->forAbilities(['support']);

        $this->assertCount(2, $forSupport->features);
        $this->assertSame('home', $forSupport->features[0]->name);
        $this->assertSame('tickets', $forSupport->features[1]->name);
    }

    public function testForAbilitiesEmptyReturnsOnlyPublic(): void
    {
        $public = new Feature('home', 'Homepage', '/', []);
        $gated = new Feature('admin', 'Admin', '/admin', ['admin']);

        $index = (new FeatureIndex())->with($public)->with($gated);

        $filtered = $index->forAbilities([]);

        $this->assertCount(1, $filtered->features);
        $this->assertSame('home', $filtered->features[0]->name);
    }

    public function testToArrayReturnsPlainEntries(): void
    {
        $index = (new FeatureIndex())
            ->with(new Feature('home', 'Homepage', '/', []))
            ->with(new Feature('admin', 'Admin', '/admin', ['admin']));

        $array = $index->toArray();

        $this->assertSame([
            ['name' => 'home', 'description' => 'Homepage', 'url' => '/', 'abilities' => []],
            ['name' => 'admin', 'description' => 'Admin', 'url' => '/admin', 'abilities' => ['admin']],
        ], $array);
    }

    public function testFromArrayOfFeatures(): void
    {
        $features = [
            new Feature('a', 'A', '/a'),
            new Feature('b', 'B', '/b'),
        ];

        $index = new FeatureIndex($features);

        $this->assertSame($features, $index->features);
    }

    public function testFeatureIsAccessibleWithEmptyAbilitiesIsPublic(): void
    {
        $feature = new Feature('home', 'Homepage', '/', []);

        $this->assertTrue($feature->isAccessibleWith([]));
        $this->assertTrue($feature->isAccessibleWith(['any']));
    }

    public function testFeatureIsAccessibleWithMatchingAbility(): void
    {
        $feature = new Feature('admin', 'Admin', '/admin', ['admin', 'support']);

        $this->assertFalse($feature->isAccessibleWith([]));
        $this->assertTrue($feature->isAccessibleWith(['admin']));
        $this->assertTrue($feature->isAccessibleWith(['support']));
        $this->assertFalse($feature->isAccessibleWith(['user']));
    }

    public function testFeatureToArray(): void
    {
        $feature = new Feature('dash', 'Dashboard', '/dash', ['user']);

        $this->assertSame([
            'name' => 'dash',
            'description' => 'Dashboard',
            'url' => '/dash',
            'abilities' => ['user'],
        ], $feature->toArray());
    }
}
