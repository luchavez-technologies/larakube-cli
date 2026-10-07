<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Traits\EmitsJsonOutput;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ResourcesCommand extends Command
{
    use EmitsJsonOutput, GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    public const TIERS = [
        'eco' => [
            'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
            'limits' => ['cpu' => '250m', 'memory' => '256Mi'],
        ],
        'standard' => [
            'requests' => ['cpu' => '250m', 'memory' => '512Mi'],
            'limits' => ['cpu' => '500m', 'memory' => '1Gi'],
        ],
        'pro' => [
            'requests' => ['cpu' => '1000m', 'memory' => '2Gi'],
            'limits' => ['cpu' => '2000m', 'memory' => '4Gi'],
        ],
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resources
        {environment? : The environment to configure}
        {--component= : Target component (default, web, horizon, queues, reverb, scheduler, ssr)}
        {--tier= : Resource preset tier (eco, standard, pro)}
        {--requests-cpu= : CPU request (e.g. 100m, 1)}
        {--requests-memory= : Memory request (e.g. 128Mi, 1Gi)}
        {--limits-cpu= : CPU limit (e.g. 250m, 2)}
        {--limits-memory= : Memory limit (e.g. 256Mi, 2Gi)}
        {--reset : Reset component resources to inherit from default}
        {--json : Emit machine-readable JSON output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure Kubernetes resource requests and limits per component';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        if (! $this->isLaraKubeProject()) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => 'Not a LaraKube project.']);
            }

            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        $environments = $config->getEnvironments();
        $envName = $this->argument('environment');

        if (! $envName) {
            if ($this->option('json') || ! $this->input->isInteractive()) {
                $envName = $environments[0] ?? 'local';
            } else {
                $envName = select(
                    label: 'Which environment do you want to configure resources for?',
                    options: $environments,
                    default: 'local',
                );
            }
        }

        if (! in_array($envName, $environments)) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => "Environment '{$envName}' not found in your blueprint."]);
            } else {
                $this->laraKubeError("Environment '{$envName}' not found in your blueprint.");
            }

            return 1;
        }

        $isJson = (bool) $this->option('json');
        $componentChoice = $this->option('component');
        $deployableComponents = $this->getDeployableComponents($config, $envName);
        $validComponents = array_merge(['default'], array_keys($deployableComponents));

        // Query mode: return all resources config as JSON
        if ($isJson && ! $componentChoice && ! $this->option('tier') && ! $this->option('requests-cpu') && ! $this->option('requests-memory') && ! $this->option('limits-cpu') && ! $this->option('limits-memory') && ! $this->option('reset')) {
            $effective = [];
            foreach ($validComponents as $c) {
                $effective[$c] = [
                    'explicit' => $config->getEnvironment($envName)?->resources[$c] ?? null,
                    'effective' => $config->getResources($envName, $c),
                ];
            }
            $this->jsonOutput([
                'success' => true,
                'environment' => $envName,
                'components' => array_keys($deployableComponents),
                'resources' => $effective,
            ]);

            return 0;
        }

        if ($componentChoice !== null) {
            if (! in_array($componentChoice, $validComponents, true)) {
                if ($isJson) {
                    $this->jsonOutput(['success' => false, 'error' => "Component '{$componentChoice}' is not valid for environment '{$envName}'. Valid components: ".implode(', ', $validComponents)]);
                } else {
                    $this->laraKubeError("Component '{$componentChoice}' is not valid for environment '{$envName}'. Valid components: ".implode(', ', $validComponents));
                }

                return 1;
            }

            if ($this->option('reset')) {
                $config->setResources($envName, $componentChoice, null);
                $this->saveProjectConfig($projectPath, $config);
                if ($isJson) {
                    $this->jsonOutput([
                        'success' => true,
                        'environment' => $envName,
                        'component' => $componentChoice,
                        'action' => 'reset',
                        'resources' => $config->getResources($envName, $componentChoice),
                    ]);
                } else {
                    $this->laraKubeInfo("Reset resources for '{$componentChoice}' in '{$envName}'.");
                    $this->printNextSteps($envName);
                }

                return 0;
            }

            $tier = $this->option('tier');
            $cpuRequest = $this->option('requests-cpu');
            $memRequest = $this->option('requests-memory');
            $cpuLimit = $this->option('limits-cpu');
            $memLimit = $this->option('limits-memory');

            if ($tier !== null) {
                if (! isset(self::TIERS[$tier])) {
                    $validTiers = implode(', ', array_keys(self::TIERS));
                    if ($isJson) {
                        $this->jsonOutput(['success' => false, 'error' => "Invalid tier '{$tier}'. Valid tiers: {$validTiers}"]);
                    } else {
                        $this->laraKubeError("Invalid tier '{$tier}'. Valid tiers: {$validTiers}");
                    }

                    return 1;
                }

                $preset = self::TIERS[$tier];
                $cpuRequest ??= $preset['requests']['cpu'];
                $memRequest ??= $preset['requests']['memory'];
                $cpuLimit ??= $preset['limits']['cpu'];
                $memLimit ??= $preset['limits']['memory'];
            }

            $toValidate = [
                'requests-cpu' => $cpuRequest,
                'requests-memory' => $memRequest,
                'limits-cpu' => $cpuLimit,
                'limits-memory' => $memLimit,
            ];

            foreach ($toValidate as $key => $val) {
                if ($val !== null && $val !== '' && ! ConfigData::isValidQuantity($val)) {
                    if ($isJson) {
                        $this->jsonOutput(['success' => false, 'error' => "Invalid Kubernetes quantity for {$key}: {$val}. Example: 100m, 1, 256Mi, 1Gi."]);
                    } else {
                        $this->laraKubeError("Invalid Kubernetes quantity for {$key}: {$val}. Example: 100m, 1, 256Mi, 1Gi.");
                    }

                    return 1;
                }
            }

            $newResources = [];
            if ($cpuRequest !== null && $cpuRequest !== '') {
                $newResources['requests']['cpu'] = $cpuRequest;
            }
            if ($memRequest !== null && $memRequest !== '') {
                $newResources['requests']['memory'] = $memRequest;
            }
            if ($cpuLimit !== null && $cpuLimit !== '') {
                $newResources['limits']['cpu'] = $cpuLimit;
            }
            if ($memLimit !== null && $memLimit !== '') {
                $newResources['limits']['memory'] = $memLimit;
            }

            $config->setResources($envName, $componentChoice, $newResources);
            $this->saveProjectConfig($projectPath, $config);

            if ($isJson) {
                $this->jsonOutput([
                    'success' => true,
                    'environment' => $envName,
                    'component' => $componentChoice,
                    'tier' => $tier,
                    'resources' => $config->getResources($envName, $componentChoice),
                ]);
            } else {
                $this->laraKubeInfo("Updated resources for '{$componentChoice}' in '{$envName}'.");
                $this->printNextSteps($envName);
            }

            return 0;
        }

        // Show current effective limits
        $this->showEffectiveResourcesTable($config, $envName);

        // Select component to configure
        $options = array_merge(['default' => 'default (all pods)'], $deployableComponents);

        $componentChoice = select(
            label: 'Which component do you want to configure?',
            options: $options,
            default: 'default',
        );

        $action = select(
            label: "What do you want to do with '{$componentChoice}' in '{$envName}'?",
            options: [
                'set' => 'Set or update resources',
                'reset' => 'Reset to inherit from default (or code defaults)',
            ],
            default: 'set',
        );

        if ($action === 'reset') {
            $config->setResources($envName, $componentChoice, null);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Reset resources for '{$componentChoice}' in '{$envName}'.");
            $this->printNextSteps($envName);

            return 0;
        }

        // Prompt for resources
        $explicit = $config->getEnvironment($envName)?->resources[$componentChoice] ?? [];
        $inherited = $componentChoice === 'default'
            ? ConfigData::DEFAULT_RESOURCES
            : $config->getResources($envName, 'default');

        $cpuRequest = $this->promptQuantity(
            'CPU Request',
            $explicit['requests']['cpu'] ?? '',
            'inherit '.($inherited['requests']['cpu'] ?? 'none'),
        );
        $cpuLimit = $this->promptQuantity(
            'CPU Limit',
            $explicit['limits']['cpu'] ?? '',
            'inherit '.($inherited['limits']['cpu'] ?? 'none'),
        );
        $memRequest = $this->promptQuantity(
            'Memory Request',
            $explicit['requests']['memory'] ?? '',
            'inherit '.($inherited['requests']['memory'] ?? 'none'),
        );
        $memLimit = $this->promptQuantity(
            'Memory Limit',
            $explicit['limits']['memory'] ?? '',
            'inherit '.($inherited['limits']['memory'] ?? 'none'),
        );

        $newResources = [];
        if ($cpuRequest !== '') {
            $newResources['requests']['cpu'] = $cpuRequest;
        }
        if ($memRequest !== '') {
            $newResources['requests']['memory'] = $memRequest;
        }
        if ($cpuLimit !== '') {
            $newResources['limits']['cpu'] = $cpuLimit;
        }
        if ($memLimit !== '') {
            $newResources['limits']['memory'] = $memLimit;
        }

        $config->setResources($envName, $componentChoice, $newResources);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("Updated resources for '{$componentChoice}' in '{$envName}'.");

        $this->printNextSteps($envName);

        return 0;
    }

    protected function showEffectiveResourcesTable(ConfigData $config, string $envName): void
    {
        $components = $this->getDeployableComponents($config, $envName);
        $headers = ['Component', 'CPU Request', 'CPU Limit', 'Memory Request', 'Memory Limit'];
        $rows = [];

        foreach (array_merge(['default'], array_keys($components)) as $component) {
            $res = $config->getResources($envName, $component);
            $rows[] = [
                $component === 'default' ? 'default (fallback)' : $component,
                $res['requests']['cpu'] ?? '-',
                $res['limits']['cpu'] ?? '-',
                $res['requests']['memory'] ?? '-',
                $res['limits']['memory'] ?? '-',
            ];
        }

        table($headers, $rows);
    }

    protected function getDeployableComponents(ConfigData $config, string $envName): array
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
        if (in_array(LaravelFeature::TASK_SCHEDULING, $features, true)) {
            $components['scheduler'] = 'scheduler';
        }
        if (in_array(LaravelFeature::SSR, $features, true)) {
            $components['ssr'] = 'ssr';
        }

        return $components;
    }

    protected function promptQuantity(string $label, string $default, string $placeholder): string
    {
        while (true) {
            $val = text(
                label: $label,
                placeholder: $placeholder,
                default: $default,
                required: false,
                hint: 'e.g. 100m, 1, 256Mi, 1Gi (leave blank to omit / inherit)',
            );

            if ($val === '') {
                return '';
            }

            if (ConfigData::isValidQuantity($val)) {
                return $val;
            }

            $this->laraKubeError("Invalid Kubernetes quantity: {$val}. Must be like 100m, 1, 256Mi, 1Gi, etc.");
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
