<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Aux\Feature;
use Fw\Aux\FeatureIndex;
use Fw\Core\ServiceProvider;

/**
 * FeatureIndexProvider
 *
 * Declares the app's navigable feature surface for agents. The built-in
 * `list_features` tool reads this index so a connected agent can build a
 * mental map of the app in a single call.
 *
 * Add this provider to config/providers.php to activate it:
 *
 *     App\Providers\FeatureIndexProvider::class,
 */
final class FeatureIndexProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(FeatureIndex::class, function (): FeatureIndex {
            // CUSTOMIZE: declare each navigable feature the agent should know about.
            // Each entry = {name, description, url, abilities?}. Public features
            // use abilities: []; gated features list required token abilities.
            return new FeatureIndex()
                ->with(new Feature(
                    name: 'dashboard',
                    description: 'Main operator dashboard — metrics and recent activity.',
                    url: '/dashboard',
                ))
                ->with(new Feature(
                    name: 'settings',
                    description: 'User account and workspace settings.',
                    url: '/settings',
                ));
        });
    }
}
