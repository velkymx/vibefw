<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\FeatureIndex;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxFeaturesCommand extends Command
{
    protected string $name = 'aux:features';

    protected string $description = 'List the agent-facing feature index (name, url, abilities)';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $index = HttpApplication::getInstance()->getContainer()->get(FeatureIndex::class);
        $features = $index->features;

        if ($features === []) {
            $this->comment('No features registered. Bind a FeatureIndex in your AuxServiceProvider to expose the app\'s navigable surface to agents.');
            return 0;
        }

        $this->newLine();
        $this->info('Agent Feature Index');
        $this->newLine();

        $rows = [];
        foreach ($features as $feature) {
            $abilities = $feature->abilities === [] ? 'public' : implode(', ', $feature->abilities);
            $rows[] = [
                $feature->name,
                $feature->description,
                $feature->url,
                $abilities,
            ];
        }

        $this->table(['Name', 'Description', 'URL', 'Abilities'], $rows);
        $this->newLine();
        $this->line('Total: ' . count($features) . ' feature(s)');
        $this->newLine();

        return 0;
    }
}
