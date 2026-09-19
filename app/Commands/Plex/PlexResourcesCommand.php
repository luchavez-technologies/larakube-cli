<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class PlexResourcesCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, StreamsProcessOutput;

    protected $signature = 'plex:resources
        {environment? : Environment whose Commons to configure — "local" (default) or a cloud environment. Omit to be prompted (used only inside a project).}
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
            $env = $this->resolvePlexEnvironment($config);
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

        $driver = DatabaseDriver::tryFrom($service);
        $poolable = $driver?->supportsPooling() ?? false;

        $actionOptions = [
            'set' => 'Set or update resources',
            'reset' => 'Reset to Commons defaults',
        ];
        if ($poolable) {
            $actionOptions['pooler'] = 'Configure connection pooler (PgBouncer)';
        }

        $action = select(
            label: "What do you want to do with '{$service}'?",
            options: $actionOptions,
            default: 'set',
        );

        if ($action === 'pooler') {
            $spec['services'][$service] = $this->promptPoolerConfig($service, $spec['services'][$service]);
        } elseif ($action === 'reset') {
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

        if (! $this->applyCommons($spec, "Applying updated Commons manifests for '{$service}'...")) {
            return 1;
        }

        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        // Plain `apply` never prunes — disabling the pooler drops it from the
        // rendered manifest, but its Deployment/Services would otherwise sit
        // there running, unmanaged, until someone notices. The `postgres`
        // Service already stopped pointing at them the moment this applied;
        // this just stops them existing at all.
        $poolerNowOff = $action === 'pooler' && ! ($spec['services'][$service]['pooler']['enabled'] ?? false);
        if ($poolerNowOff) {
            $this->withSpin('Removing PgBouncer (pooler disabled)...', function () use ($kubectl, $ns) {
                $this->runStreaming("{$kubectl} delete deploy/pgbouncer svc/pgbouncer svc/".DatabaseDriver::POSTGRESQL->poolerPrimaryServiceName()." -n {$ns} --ignore-not-found");

                return true;
            });
        }

        $rolloutTarget = ($action === 'pooler' && ! $poolerNowOff) ? 'pgbouncer' : $service;
        if (! $poolerNowOff) {
            $this->withSpin("Waiting for {$rolloutTarget} to roll out...", fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/{$rolloutTarget} -n {$ns} --timeout=120s",
                130,
            ));
        }

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
                isset($cfg['pooler']) ? ($cfg['pooler']['enabled'] ? "on ({$cfg['pooler']['mode']})" : 'off') : '—',
            ];
        }

        table(['Service', 'Memory Limit', 'Storage', 'Pooler'], $rows);
    }
}
