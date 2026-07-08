<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexResourcesCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, StreamsProcessOutput;

    protected $signature = 'plex:resources
        {environment=local : The environment whose Commons to configure (local, or a cloud environment)}
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}';

    protected $description = 'Configure Kubernetes resource limits and storage for Commons services';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Resource Configuration');

        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = (string) $this->argument('environment');
            $this->plexContext = $this->environmentContextOrCurrent($config, $env);
        } else {
            $target = $this->askForClusterContext();
            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }
            $this->plexContext = $target;
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable.');

            return 1;
        }

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeError('No Commons found on this cluster. Run `larakube plex:init` first.');

            return 1;
        }

        $this->showResourceTable($spec);

        $enabled = $this->enabledCommonsServices($spec);
        if (empty($enabled)) {
            $this->laraKubeError('No services are enabled on this Commons.');

            return 1;
        }

        $service = select(
            label: 'Which Commons service do you want to configure?',
            options: array_combine($enabled, $enabled),
        );

        $action = select(
            label: "What do you want to do with '{$service}'?",
            options: [
                'set' => 'Set or update resources',
                'reset' => 'Reset to Commons defaults',
            ],
            default: 'set',
        );

        if ($action === 'reset') {
            $normalized = $this->normalizeCommonsSpec(['services' => []]);
            $defaults = $normalized['services'][$service] ?? [];
            if (isset($defaults['memory'])) {
                $spec['services'][$service]['memory'] = $defaults['memory'];
            }
            if (isset($defaults['storage'])) {
                $spec['services'][$service]['storage'] = $defaults['storage'];
            }
        } else {
            $current = $spec['services'][$service];

            $memory = $this->promptQuantity(
                label: 'Memory Limit',
                current: $current['memory'] ?? '—',
                hint: 'e.g. 512Mi, 1Gi, 2Gi',
            );

            if ($memory !== '') {
                $spec['services'][$service]['memory'] = $memory;
            }

            if (isset($current['storage'])) {
                $storage = $this->promptQuantity(
                    label: 'Storage Size (PVC)',
                    current: $current['storage'],
                    hint: 'e.g. 10Gi, 20Gi — shrinking requires manual PVC resize',
                );
                if ($storage !== '') {
                    $spec['services'][$service]['storage'] = $storage;
                }
            }
        }

        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => $this->indentedSpecJson($spec),
        ])->render();

        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        $this->withSpin("Applying updated Commons manifests for '{$service}'...", function () use ($manifest, $ns, $kubectl) {
            $tmp = sys_get_temp_dir().'/larakube-plex-commons.yaml';
            file_put_contents($tmp, $manifest);
            $this->runStreaming("{$kubectl} apply -n {$ns} -f {$tmp}");
            @unlink($tmp);

            return true;
        });

        $this->withSpin("Waiting for {$service} to roll out...", fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/{$service} -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeInfo("✅ Commons '{$service}' updated successfully.");
        $this->newLine();
        $this->showResourceTable($spec);

        return 0;
    }

    protected function showResourceTable(array $spec): void
    {
        $rows = [];
        foreach ($spec['services'] as $name => $cfg) {
            if (! ($cfg['enabled'] ?? false)) {
                continue;
            }
            $rows[] = [
                $name,
                $cfg['memory'] ?? '—',
                isset($cfg['storage']) ? $cfg['storage'] : '—',
            ];
        }

        table(['Service', 'Memory Limit', 'Storage'], $rows);
    }

    protected function promptQuantity(string $label, string $current, string $hint): string
    {
        while (true) {
            $val = text(
                label: $label,
                placeholder: 'leave blank to keep current ('.$current.')',
                default: '',
                required: false,
                hint: $hint,
            );

            if ($val === '') {
                return '';
            }

            if (ConfigData::isValidQuantity($val)) {
                return $val;
            }

            $this->laraKubeError("Invalid Kubernetes quantity: {$val}. Use formats like 512Mi, 1Gi, 10Gi.");
        }
    }

    protected function indentedSpecJson(array $spec): string
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace('/^/m', '    ', $json);
    }
}
