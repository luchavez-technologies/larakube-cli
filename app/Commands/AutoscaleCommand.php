<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class AutoscaleCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autoscale {environment? : The environment to configure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure HorizontalPodAutoscaler (min/max replicas) per component';

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

        // Metrics-server ships as a default k3s addon on both a VPS-backed and
        // a managed cluster, but autoscaling against local dev traffic isn't
        // meaningful — same reasoning topologySpreadConstraints and the
        // production-only wait-for-deps override already use.
        $environments = $config->getCloudEnvironments();

        if (empty($environments)) {
            $this->laraKubeError('No cloud environments configured yet.');
            $this->line('  👉 Run <fg=yellow>larakube env <name></> or <fg=yellow>larakube cloud:configure</> first.');

            return 1;
        }

        $envName = $this->argument('environment');

        if (! $envName) {
            $envName = select(
                label: 'Which environment do you want to configure autoscaling for?',
                options: $environments,
                default: $environments[0],
            );
        }

        if (! in_array($envName, $environments)) {
            $this->laraKubeError("'{$envName}' isn't a cloud environment in your blueprint.");

            return 1;
        }

        $this->showEffectiveAutoscaleTable($config, $envName);

        $components = $this->getAutoscalableComponents($config, $envName);

        $componentChoice = select(
            label: 'Which component do you want to configure?',
            options: $components,
            default: array_key_first($components),
        );

        $existing = $config->getAutoscale($envName, $componentChoice);

        $action = select(
            label: "What do you want to do with '{$componentChoice}' in '{$envName}'?",
            options: [
                'set' => $existing ? 'Update autoscaling' : 'Enable autoscaling (HPA)',
                'disable' => 'Disable — use a fixed replica count instead (larakube replicas)',
            ],
            default: 'set',
        );

        if ($action === 'disable') {
            $config->setAutoscale($envName, $componentChoice, null);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Disabled autoscaling for '{$componentChoice}' in '{$envName}'.");
            $this->line('  <fg=gray>It now uses a fixed replica count — see: larakube replicas '.$envName.'</>');
            $this->printNextSteps($envName);

            return 0;
        }

        $min = $this->promptInt('Minimum replicas', $existing['min'] ?? 1, min: 1);
        $max = $this->promptInt('Maximum replicas', $existing['max'] ?? max($min, 3), min: $min);
        $cpu = $this->promptInt('Target CPU utilization %', $existing['cpu'] ?? 70, min: 1, max: 100);

        $config->setAutoscale($envName, $componentChoice, ['min' => $min, 'max' => $max, 'cpu' => $cpu]);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("'{$componentChoice}' in '{$envName}' now autoscales {$min}-{$max} replicas, targeting {$cpu}% CPU.");
        $this->line('  <fg=gray>Any fixed replica count from `larakube replicas` is ignored while this is active.</>');

        $this->printNextSteps($envName);

        return 0;
    }

    protected function showEffectiveAutoscaleTable(ConfigData $config, string $envName): void
    {
        $components = $this->getAutoscalableComponents($config, $envName);
        $headers = ['Component', 'Autoscaling'];
        $rows = [];

        foreach (array_keys($components) as $component) {
            $autoscale = $config->getAutoscale($envName, $component);
            $rows[] = [
                $component,
                $autoscale
                    ? "{$autoscale['min']}-{$autoscale['max']} replicas @ {$autoscale['cpu']}% CPU"
                    : 'fixed ('.$config->getReplicas($envName, $component).' replicas)',
            ];
        }

        table($headers, $rows);
    }

    /**
     * Components worth autoscaling by pod count: web (traffic-driven) and
     * queues/ssr (stateless, safely horizontal). Deliberately excludes:
     * horizon (already does its own PROCESS-level autoscaling inside one
     * pod — a second HPA layer on top is redundant, not additive); reverb
     * (WebSocket — scaling replicas silently breaks broadcasts across
     * clients on different pods unless REVERB_SCALING_ENABLED + Redis is
     * already wired up, which this CLI doesn't set up); scheduler (a
     * CronJob has no replica count to autoscale at all).
     */
    protected function getAutoscalableComponents(ConfigData $config, string $envName): array
    {
        $components = ['web' => 'web (PHP / Nginx)'];

        $features = $config->getFeatures($envName);

        if (in_array(LaravelFeature::QUEUES, $features, true)) {
            $components['queues'] = 'queues';
        }
        if (in_array(LaravelFeature::SSR, $features, true)) {
            $components['ssr'] = 'ssr';
        }

        return $components;
    }

    protected function promptInt(string $label, int $default, int $min, ?int $max = null): int
    {
        while (true) {
            $val = text(label: $label, default: (string) $default);

            if (ctype_digit($val) && (int) $val >= $min && ($max === null || (int) $val <= $max)) {
                return (int) $val;
            }

            $range = $max !== null ? "{$min}-{$max}" : "{$min}+";
            $this->laraKubeError("Invalid value: {$val}. Must be an integer in the range {$range}.");
        }
    }

    protected function printNextSteps(string $envName): void
    {
        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');
        $this->line("  Run <fg=yellow>larakube cloud:deploy {$envName}</> to apply changes to the cloud.");
    }
}
