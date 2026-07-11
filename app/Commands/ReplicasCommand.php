<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\LaravelFeature;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ReplicasCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'replicas {environment? : The environment to configure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure the number of pod replicas per component';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        $environments = $config->getEnvironments();
        $envName = $this->argument('environment');

        if (! $envName) {
            $envName = select(
                label: 'Which environment do you want to configure replicas for?',
                options: $environments,
                default: 'local',
            );
        }

        if (! in_array($envName, $environments)) {
            $this->laraKubeError("Environment '{$envName}' not found in your blueprint.");

            return 1;
        }

        // Show current effective replica counts
        $this->showEffectiveReplicasTable($config, $envName);

        // Select component to configure
        $components = $this->getScalableComponents($config, $envName);
        $options = array_merge(['default' => 'default (all pods)'], $components);

        $componentChoice = select(
            label: 'Which component do you want to configure?',
            options: $options,
            default: 'default',
        );

        $action = select(
            label: "What do you want to do with '{$componentChoice}' in '{$envName}'?",
            options: [
                'set' => 'Set replica count',
                'reset' => 'Reset to inherit from default (or strategy default)',
            ],
            default: 'set',
        );

        if ($action === 'reset') {
            $config->setReplicas($envName, $componentChoice, null);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Reset replicas for '{$componentChoice}' in '{$envName}'.");
            $this->printNextSteps($envName);

            return 0;
        }

        $inherited = $componentChoice === 'default'
            ? $this->strategyDefault($config, $envName)
            : $config->getReplicas($envName, 'default');

        $explicit = $config->getEnvironment($envName)?->replicas[$componentChoice] ?? null;

        $replicas = $this->promptReplicaCount($explicit, $inherited);

        $config->setReplicas($envName, $componentChoice, $replicas);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("Updated replicas for '{$componentChoice}' in '{$envName}': {$replicas}");

        $this->printNextSteps($envName);

        return 0;
    }

    protected function showEffectiveReplicasTable(ConfigData $config, string $envName): void
    {
        $components = $this->getScalableComponents($config, $envName);
        $headers = ['Component', 'Replicas'];
        $rows = [];

        foreach (array_merge(['default'], array_keys($components)) as $component) {
            $rows[] = [
                $component === 'default' ? 'default (fallback)' : $component,
                (string) $config->getReplicas($envName, $component),
            ];
        }

        table($headers, $rows);
    }

    /**
     * Components that actually have a replica count to configure — the same
     * set ResourcesCommand offers, minus the scheduler: it's a CronJob (spawns
     * one-off Jobs on schedule), which has no persistent replica count.
     */
    protected function getScalableComponents(ConfigData $config, string $envName): array
    {
        $components = ['web' => 'web (PHP / Nginx)'];

        $features = $config->getFeatures($envName);

        if (in_array(LaravelFeature::HORIZON, $features, true)) {
            $components['horizon'] = 'horizon';
        }
        if (in_array(LaravelFeature::QUEUES, $features, true)) {
            $components['queues'] = 'queues';
        }
        if (in_array(LaravelFeature::REVERB, $features, true)) {
            $components['reverb'] = 'reverb';
        }
        if (in_array(LaravelFeature::SSR, $features, true)) {
            $components['ssr'] = 'ssr';
        }

        return $components;
    }

    /** The code-default replica count before any user override: 2 for multi-node-ha cloud envs, else 1. */
    protected function strategyDefault(ConfigData $config, string $envName): int
    {
        return ($envName !== 'local' && $config->getStrategy($envName) === DeploymentStrategy::MULTI_NODE_HA) ? 2 : 1;
    }

    protected function promptReplicaCount(?int $explicit, int $inherited): int
    {
        while (true) {
            $val = text(
                label: 'Replica count',
                placeholder: "inherit {$inherited}",
                default: $explicit !== null ? (string) $explicit : '',
                required: false,
                hint: 'A positive integer (leave blank to inherit).',
            );

            if ($val === '') {
                return $inherited;
            }

            if (ctype_digit($val) && (int) $val >= 1) {
                return (int) $val;
            }

            $this->laraKubeError("Invalid replica count: {$val}. Must be a positive integer.");
        }
    }

    protected function printNextSteps(string $envName): void
    {
        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');
        if ($envName === 'local') {
            $this->line('  Run <fg=yellow>larakube up</> to apply changes locally.');
        } else {
            $this->line("  Run <fg=yellow>larakube cloud:deploy {$envName}</> to apply changes to the cloud.");
        }
    }
}
